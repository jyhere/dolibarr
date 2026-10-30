<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\Documents;

use Dolibarr\Core\Database\BoundStatement;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Database\Driver;
use Dolibarr\Core\Database\LegacyDriver;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Files\EcmFileRepository;
use Dolibarr\Core\Files\ExternalLinkRepository;
use Dolibarr\Core\Files\FileIndex;
use Dolibarr\Core\Files\FileStorage;
use Dolibarr\Core\Files\Thumbnails;
use Dolibarr\Core\Files\UploadPolicy;
use Dolibarr\Core\Http\UploadedFile;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyEntityScope;
use Dolibarr\Core\Legacy\LegacyExtraFieldCatalog;
use Dolibarr\Core\Legacy\LegacyFileObjects;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyTranslator;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Logger;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Core\Orm\Snapshots;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Module\Documents\Service\ActionResult;
use Dolibarr\Module\Documents\Service\AttachedFilesService;
use Dolibarr\Module\Documents\Service\DocumentsTarget;
use Dolibarr\Module\Documents\Service\LinkWriteException;
use Dolibarr\Module\Documents\Service\LinkWriter;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use Dolibarr\Tests\Integration\Fixture\RecordingTriggers;
use Dolibarr\Tests\Integration\Fixture\RenamingUploadedFileMover;
use Dolibarr\Tests\Integration\Fixture\ScriptedHooks;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of AttachedFilesService beyond the parity scenarios: refusals, overwrite, hostile names, objects that
 * are not the target's, hooks and failing triggers. Rows and directories are removed by tearDown (no transaction:
 * a failing trigger rolls back a nested one).
 */
final class AttachedFilesServiceTest extends TestCase
{
	use OverridesLegacyConf;

	private Connection $connection;

	private LegacyContext $context;

	private EcmFileRepository $files;

	private ExternalLinkRepository $links;

	private \User $object;

	private \User $otherObject;

	private DocumentsTarget $target;

	/** @var list<string> */
	private array $createdDirectories = [];

	protected function setUp(): void
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';

		$this->context = LegacyContext::fromGlobals();
		foreach (['errors', 'link', 'other'] as $domain) {
			$this->context->langs->load($domain);
		}
		$this->connection = Connection::forLegacy($this->context->db);
		$this->files = $this->filesOn($this->connection);
		$this->links = (new ExternalLinkRepository($this->orm($this->connection)))->withoutEntityScope();

		$this->object = $this->testUser();
		$this->otherObject = $this->testUser();
		foreach ([$this->object, $this->otherObject] as $object) {
			$this->createdDirectories[] = $this->directoryOf($object);
			mkdir($this->directoryOf($object), 0777, true);
		}
		$this->target = new DocumentsTarget(
			new EntityDefinition('user', User::class, UserRepository::class, 'user', 'user', UserPermissionChecker::class, UserCardChrome::class, 'user_show', \User::class, '/user/class/user.class.php'),
			$this->object,
			$this->directoryOf($this->object),
			'user',
			true
		);
		$this->setConf('MAIN_UPLOAD_DOC', '2048');
		RecordingTriggers::install();
	}

	protected function tearDown(): void
	{
		RecordingTriggers::uninstall();
		$this->restoreConf();
		foreach ([$this->object, $this->otherObject] as $object) {
			$relativeDirectory = 'users/'.$object->id;
			$this->connection->execute(
				'DELETE FROM @ecm_files_extrafields WHERE fk_object IN (SELECT rowid FROM @ecm_files WHERE filepath = :filepath)',
				['filepath' => $relativeDirectory]
			);
			$this->connection->execute('DELETE FROM @ecm_files WHERE filepath = :filepath', ['filepath' => $relativeDirectory]);
			$this->connection->execute(
				'DELETE FROM @links WHERE objecttype = :objecttype AND objectid = :objectid',
				['objecttype' => 'user', 'objectid' => (int) $object->id]
			);
		}
		foreach ($this->createdDirectories as $directory) {
			$this->removeTree($directory);
		}
	}

	public function testUploadRefusesAllFilesWhenOneIsInvalid(): void
	{
		$result = $this->service()->upload($this->target, [$this->upload('good.txt'), $this->upload('bad__name__.txt')], false, '');

		self::assertFalse($result->ok);
		self::assertSame([['type' => 'errors', 'text' => $this->text('ErrorWrongFileName')]], $result->messages);
		self::assertFileDoesNotExist($this->directoryOf($this->object).'/good.txt');
		self::assertSame([], $this->files->inDirectory((int) $this->object->entity, 'users/'.$this->object->id));
	}

	public function testUploadReportsEveryMissingFileBeforeRefusing(): void
	{
		$tooLarge = new UploadedFile('big.bin', '', '', 0, UPLOAD_ERR_INI_SIZE);
		$missing = new UploadedFile('x__y__.txt', '', '', 0, UPLOAD_ERR_NO_FILE);

		$result = $this->service()->upload($this->target, [$tooLarge, $missing], false, '');

		self::assertSame([
			['type' => 'errors', 'text' => $this->text('ErrorFileSizeTooLarge')],
			['type' => 'errors', 'text' => $this->text('ErrorFieldRequired', $this->context->langs->transnoentitiesnoconv('File'))],
			['type' => 'errors', 'text' => $this->text('ErrorWrongFileName')],
		], $result->messages);
	}

	public function testUploadRefusesTooManyFiles(): void
	{
		$this->setConf('MAIN_SECURITY_MAX_ATTACHMENT_ON_FORMS', 1);

		$result = $this->service()->upload($this->target, [$this->upload('one.txt'), $this->upload('two.txt')], false, '');

		self::assertSame([['type' => 'errors', 'text' => $this->text('ErrorTooMuchFileInForm', '1')]], $result->messages);
		self::assertFileDoesNotExist($this->directoryOf($this->object).'/one.txt');
	}

	public function testUploadWithoutMainUploadDocDoesNothing(): void
	{
		$this->setConf('MAIN_UPLOAD_DOC', '');

		$result = $this->service()->upload($this->target, [$this->upload('report.txt')], false, '');

		self::assertTrue($result->ok);
		self::assertSame([], $result->messages);
		self::assertFileDoesNotExist($this->directoryOf($this->object).'/report.txt');
		self::assertSame([], RecordingTriggers::calls());
	}

	public function testUploadCreatesTheDirectoryAndThumbnails(): void
	{
		$this->removeTree($this->directoryOf($this->object));
		$imagePath = sys_get_temp_dir().'/mvc-upload-'.bin2hex(random_bytes(4));
		$image = imagecreatetruecolor(600, 400);
		self::assertNotFalse($image);
		imagepng($image, $imagePath);

		$result = $this->service()->upload($this->target, [new UploadedFile('Photo.PNG', $imagePath, 'image/png', (int) filesize($imagePath), UPLOAD_ERR_OK)], false, '');

		self::assertTrue($result->ok);
		self::assertFileExists($this->directoryOf($this->object).'/Photo.png');
		self::assertFileExists($this->directoryOf($this->object).'/thumbs/Photo_small.png');
		self::assertFileExists($this->directoryOf($this->object).'/thumbs/Photo_mini.png');
		self::assertNotNull($this->files->findByPath((int) $this->object->entity, 'users/'.$this->object->id, 'Photo.png'));
	}

	public function testUploadStopsAtAnExtensionNotAllowed(): void
	{
		$result = $this->service()->upload($this->target, [$this->upload('first.txt'), $this->upload('script.php'), $this->upload('last.txt')], false, '');

		self::assertSame([['type' => 'errors', 'text' => $this->text('ErrorFilenameExtensionNotAllowed', 'script.php')]], $result->messages, 'return -1: no FileTransferComplete for the first file');
		self::assertFileExists($this->directoryOf($this->object).'/first.txt');
		self::assertFileDoesNotExist($this->directoryOf($this->object).'/last.txt');
	}

	public function testOverwriteRecreatesTheIndexRow(): void
	{
		$service = $this->service();
		$service->upload($this->target, [$this->upload('doc.txt', 'first')], false, '');
		$first = $this->files->findByPath((int) $this->object->entity, 'users/'.$this->object->id, 'doc.txt');
		self::assertNotNull($first);

		$refused = $service->upload($this->target, [$this->upload('doc.txt', 'second')], false, '');
		self::assertSame([['type' => 'errors', 'text' => $this->text('ErrorFileAlreadyExists')]], $refused->messages);
		self::assertSame('first', file_get_contents($this->directoryOf($this->object).'/doc.txt'));
		$unchanged = $this->files->findByPath((int) $this->object->entity, 'users/'.$this->object->id, 'doc.txt');
		self::assertNotNull($unchanged);
		self::assertSame($first->id, $unchanged->id);

		RecordingTriggers::forget();
		$overwritten = $service->upload($this->target, [$this->upload('doc.txt', 'second')], true, '');
		self::assertSame([['type' => 'mesgs', 'text' => $this->text('FileTransferComplete')]], $overwritten->messages);
		self::assertSame('second', file_get_contents($this->directoryOf($this->object).'/doc.txt'));
		$rows = $this->files->inDirectory((int) $this->object->entity, 'users/'.$this->object->id);
		self::assertCount(1, $rows);
		self::assertNotSame($first->id, $rows[0]->id);
		self::assertSame(md5('second'), $rows[0]->label);
		self::assertSame(['ECMFILES_CREATE'], array_column(RecordingTriggers::calls(), 'action'), 'the old row is removed without trigger');
	}

	public function testOverwriteSurvivesAFailureToRemoveTheOldIndexRow(): void
	{
		$this->service()->upload($this->target, [$this->upload('doc.txt', 'first')], false, '');

		$failing = $this->connectionFailingOn('DELETE', 'Deadlock found');
		$service = $this->service(null, $failing);

		$result = $service->upload($this->target, [$this->upload('doc.txt', 'second')], true, '');

		self::assertSame([['type' => 'mesgs', 'text' => $this->text('FileTransferComplete')]], $result->messages);
		self::assertSame('second', file_get_contents($this->directoryOf($this->object).'/doc.txt'));
	}

	public function testDatabaseWarningDoesNotShowTheSql(): void
	{
		file_put_contents($this->directoryOf($this->object).'/kept.txt', 'content');
		addFileIntoDatabaseIndex($this->directoryOf($this->object), 'kept.txt', 'kept.txt', 'uploaded', 0, $this->object);
		$row = $this->files->findByPath((int) $this->object->entity, 'users/'.$this->object->id, 'kept.txt');
		self::assertNotNull($row);

		$result = $this->service(null, $this->connectionFailingOn('UPDATE', 'Table is broken'))->share($this->target, (int) $row->id, true);

		self::assertSame([['type' => 'warnings', 'text' => 'Error Table is broken']], $result->messages);
		self::assertStringNotContainsString('SQL', $result->messages[0]['text']);
	}

	public function testDeleteFileUsesTheBasenameOfUrlfile(): void
	{
		$siblingDirectory = DOL_DATA_ROOT.'/users/sibling-'.bin2hex(random_bytes(4));
		$this->createdDirectories[] = $siblingDirectory;
		mkdir($siblingDirectory, 0777, true);
		file_put_contents($siblingDirectory.'/x.pdf', 'outside');
		file_put_contents($this->directoryOf($this->object).'/x.pdf', 'inside');

		$result = $this->service()->deleteFile($this->target, '../'.basename($siblingDirectory).'/x.pdf');

		self::assertSame([['type' => 'mesgs', 'text' => $this->text('FileWasRemoved', 'x.pdf')]], $result->messages);
		self::assertFileDoesNotExist($this->directoryOf($this->object).'/x.pdf');
		self::assertFileExists($siblingDirectory.'/x.pdf');

		file_put_contents($this->directoryOf($this->object).'/.htaccess', 'deny from all');
		$refused = $this->service()->deleteFile($this->target, '../../.htaccess');
		self::assertSame([['type' => 'errors', 'text' => $this->text('ErrorFailToDeleteFile', '.htaccess')]], $refused->messages);
		self::assertFileExists($this->directoryOf($this->object).'/.htaccess', 'refused by the path guard');
	}

	public function testDeleteFileTriggerFailureIsAWarning(): void
	{
		file_put_contents($this->directoryOf($this->object).'/kept.txt', 'content');
		addFileIntoDatabaseIndex($this->directoryOf($this->object), 'kept.txt', 'kept.txt', 'uploaded', 0, $this->object);
		RecordingTriggers::failOn('ECMFILES_DELETE', 'The index row is kept');

		$result = $this->service()->deleteFile($this->target, 'kept.txt');

		self::assertSame([
			['type' => 'warnings', 'text' => 'The index row is kept'],
			['type' => 'mesgs', 'text' => $this->text('FileWasRemoved', 'kept.txt')],
		], $result->messages);
		self::assertTrue($result->ok);
		self::assertFileDoesNotExist($this->directoryOf($this->object).'/kept.txt');
	}

	public function testFilesOfAnObjectOfEntityZeroKeepTheirIndexInSync(): void
	{
		// The default admin has the entity 0: the legacy writes and looks up its index rows with the entity 0
		$this->object->entity = 0;
		$relativeDirectory = 'users/'.$this->object->id;
		$service = $this->service();

		$service->upload($this->target, [$this->upload('doc.txt', 'first')], false, '');
		$overwritten = $service->upload($this->target, [$this->upload('doc.txt', 'second')], true, '');
		self::assertSame([['type' => 'mesgs', 'text' => $this->text('FileTransferComplete')]], $overwritten->messages);
		$rows = $this->files->inDirectory(0, $relativeDirectory);
		self::assertCount(1, $rows);
		self::assertSame(md5('second'), $rows[0]->label);

		$shared = $service->share($this->target, (int) $rows[0]->id, true);
		self::assertSame([], $shared->messages);
		$sharedRow = $this->files->find((int) $rows[0]->id);
		self::assertNotNull($sharedRow);
		self::assertNotNull($sharedRow->share);

		$renamed = $service->rename($this->target, 'doc.txt', 'renamed.txt');
		self::assertSame([['type' => 'mesgs', 'text' => $this->text('FileRenamed')]], $renamed->messages);
		$renamedRows = $this->files->inDirectory(0, $relativeDirectory);
		self::assertCount(1, $renamedRows);
		self::assertSame('renamed.txt', $renamedRows[0]->filename);
		self::assertSame($rows[0]->id, $renamedRows[0]->id, 'the row follows the file');

		RecordingTriggers::forget();
		$deleted = $service->deleteFile($this->target, 'renamed.txt');
		self::assertSame([['type' => 'mesgs', 'text' => $this->text('FileWasRemoved', 'renamed.txt')]], $deleted->messages);
		self::assertSame(['ECMFILES_DELETE'], array_column(RecordingTriggers::calls(), 'action'));
		self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @ecm_files WHERE filepath = :filepath', ['filepath' => $relativeDirectory]));
	}

	public function testTargetEntities(): void
	{
		$entity = (int) $this->context->conf->entity;
		$this->object->entity = 0;
		self::assertSame(0, $this->target->indexEntity(), 'written and looked up with the entity of the object, 0 included');
		self::assertSame($entity, $this->target->listingEntity(), 'listed with the current entity when the object has none');

		$this->object->entity = 7;
		self::assertSame(7, $this->target->indexEntity());
		self::assertSame(7, $this->target->listingEntity());

		$this->object->entity = null;
		self::assertSame($entity, $this->target->indexEntity());
		self::assertSame($entity, $this->target->listingEntity());
	}

	public function testDeleteLinkOfAnotherObjectIsDenied(): void
	{
		$otherLink = $this->legacyLink($this->otherObject, 'https://example.com/other');
		RecordingTriggers::forget();

		foreach ([$otherLink, $otherLink + 1000000] as $linkId) {
			try {
				$this->service()->deleteLink($this->target, $linkId);
				self::fail('AccessDeniedException expected');
			} catch (AccessDeniedException) {
			}
		}
		try {
			$this->service()->updateLink($this->target, $otherLink, 'https://example.com/changed', 'Changed', false);
			self::fail('AccessDeniedException expected');
		} catch (AccessDeniedException) {
		}

		$kept = $this->links->find($otherLink);
		self::assertNotNull($kept);
		self::assertSame('https://example.com/other', $kept->url);
		self::assertSame([], RecordingTriggers::calls());
	}

	public function testUpdateOfAMissingLinkDoesNothing(): void
	{
		$result = $this->service()->updateLink($this->target, 2000000000, 'https://example.com', '', false);

		self::assertSame([], $result->messages);
		self::assertSame([], RecordingTriggers::calls());
	}

	public function testShareOfAnotherObjectsFileIsDenied(): void
	{
		file_put_contents($this->directoryOf($this->otherObject).'/secret.txt', 'content');
		addFileIntoDatabaseIndex($this->directoryOf($this->otherObject), 'secret.txt', 'secret.txt', 'uploaded', 0, $this->otherObject);
		$row = $this->files->findByPath((int) $this->otherObject->entity, 'users/'.$this->otherObject->id, 'secret.txt');
		self::assertNotNull($row);
		RecordingTriggers::forget();

		try {
			$this->service()->share($this->target, (int) $row->id, true);
			self::fail('AccessDeniedException expected');
		} catch (AccessDeniedException) {
		}

		$after = $this->files->find((int) $row->id);
		self::assertNotNull($after);
		self::assertNull($after->share);
		self::assertSame([], RecordingTriggers::calls());

		$missing = $this->service()->share($this->target, 2000000000, true);
		self::assertSame([], $missing->messages);
	}

	public function testFileOfAnObjectOfEntityZeroIndexedByTheListingCanBeSharedAfterARename(): void
	{
		// The default admin has the entity 0; list_of_documents() indexes the files it does not know with the current
		// entity, and the rename form posts the id of that row
		$this->object->entity = 0;
		$entity = (int) $this->context->conf->entity;
		$service = $this->service();
		file_put_contents($this->directoryOf($this->object).'/doc.txt', 'content');
		$renamed = $service->rename($this->target, 'doc.txt', 'renamed.txt');
		self::assertSame([['type' => 'mesgs', 'text' => $this->text('FileRenamed')]], $renamed->messages);
		$this->indexAsTheListing($this->object);
		$row = $this->files->findByPath($entity, 'users/'.$this->object->id, 'renamed.txt');
		self::assertNotNull($row);

		$shared = $service->share($this->target, (int) $row->id, true);
		self::assertSame([], $shared->messages);
		self::assertNotEmpty($this->files->find((int) $row->id)?->share);

		$unshared = $service->share($this->target, (int) $row->id, false);
		self::assertSame([], $unshared->messages);
		self::assertEmpty($this->files->find((int) $row->id)?->share);
	}

	public function testShareOfARowOfTheDirectoryInAnotherEntityIsDenied(): void
	{
		file_put_contents($this->directoryOf($this->object).'/doc.txt', 'content');
		addFileIntoDatabaseIndex($this->directoryOf($this->object), 'doc.txt', 'doc.txt', 'uploaded', 0, $this->object);
		$row = $this->files->findByPath((int) $this->object->entity, 'users/'.$this->object->id, 'doc.txt');
		self::assertNotNull($row);
		$this->connection->execute('UPDATE @ecm_files SET entity = :entity WHERE rowid = :id', ['entity' => 999, 'id' => (int) $row->id]);

		$this->expectException(AccessDeniedException::class);
		$this->service()->share($this->target, (int) $row->id, true);
	}

	public function testRenameOfAnUnindexedFileCreatesItsRow(): void
	{
		file_put_contents($this->directoryOf($this->object).'/plain.txt', 'content');

		$result = $this->service()->rename($this->target, 'plain.txt', 'renamed.txt');

		self::assertSame([['type' => 'mesgs', 'text' => $this->text('FileRenamed')]], $result->messages);
		self::assertFileExists($this->directoryOf($this->object).'/renamed.txt');
		$row = $this->files->findByPath((int) $this->object->entity, 'users/'.$this->object->id, 'renamed.txt');
		self::assertNotNull($row);
		self::assertSame('unknown', $row->genOrUploaded);
		self::assertSame('plain.txt', $row->fullpathOrig);
		self::assertSame(['ECMFILES_CREATE'], array_column(RecordingTriggers::calls(), 'action'));
	}

	public function testRenameRefusesNamesLikeTheLegacy(): void
	{
		file_put_contents($this->directoryOf($this->object).'/plain.txt', 'content');

		$wrong = $this->service()->rename($this->target, 'plain.txt', 'a__b__.txt');
		$dot = $this->service()->rename($this->target, 'plain.txt', '.hidden');
		$same = $this->service()->rename($this->target, 'plain.txt', 'plain.txt');
		$executable = $this->service()->rename($this->target, 'plain.txt', 'run.php');

		self::assertSame([['type' => 'errors', 'text' => $this->text('ErrorWrongFileName')]], $wrong->messages);
		self::assertSame([['type' => 'errors', 'text' => $this->text('ErrorFilenameCantStartWithDot', '.hidden')]], $dot->messages);
		self::assertSame([], $same->messages);
		self::assertSame([['type' => 'mesgs', 'text' => $this->text('FileRenamed')]], $executable->messages);
		self::assertFileExists($this->directoryOf($this->object).'/run.php.noexe');
	}

	public function testRenameHookShortCircuits(): void
	{
		file_put_contents($this->directoryOf($this->object).'/plain.txt', 'content');
		$hooks = new ScriptedHooks(['renameUploadedFile' => ['code' => 1]]);

		$result = $this->service($hooks)->rename($this->target, 'plain.txt', 'renamed.txt');

		self::assertSame([], $result->messages);
		self::assertSame(['renameUploadedFile'], $hooks->calls);
		$parameters = $hooks->parameters['renameUploadedFile'];
		self::assertSame('plain.txt', $parameters['filenamefrom']);
		self::assertSame('renamed.txt', $parameters['filenameto']);
		self::assertSame($this->directoryOf($this->object), $parameters['upload_dir']);
		self::assertSame('actionlinkedfiles', $parameters['currentcontext']);
		self::assertFileExists($this->directoryOf($this->object).'/plain.txt');
		self::assertFileDoesNotExist($this->directoryOf($this->object).'/renamed.txt');
	}

	public function testTriggerFailureOnLinkCreateRollsBack(): void
	{
		RecordingTriggers::failOn('LINK_CREATE', 'No link today');

		$result = $this->service()->addLink($this->target, 'https://example.com/page', 'Page');

		self::assertFalse($result->ok);
		self::assertSame([['type' => 'errors', 'text' => $this->text('ErrorFileNotLinked')]], $result->messages);
		self::assertSame([], $this->links->forObject('user', (int) $this->object->id, (int) $this->context->conf->entity));
	}

	public function testDuplicateLinkLabelIsNotLinked(): void
	{
		$service = $this->service();
		$service->addLink($this->target, 'https://example.com/first', 'Same');

		$result = $service->addLink($this->target, 'https://example.com/second', 'Same');

		self::assertSame([['type' => 'errors', 'text' => $this->text('ErrorFileNotLinked')]], $result->messages);
		$links = $this->links->forObject('user', (int) $this->object->id, (int) $this->context->conf->entity);
		self::assertCount(1, $links);
		self::assertSame('https://example.com/first', $links[0]->url);

		$writer = new LinkWriter($this->links, $this->connection, new LegacyTriggers($this->context), new LegacyFileObjects($this->context->db), new LegacyConfig($this->context), $this->context);
		try {
			$writer->create($this->target, 'https://example.com/third', 'Same');
			self::fail('LinkWriteException expected');
		} catch (LinkWriteException $e) {
			self::assertSame('ErrorDuplicateField', $e->getMessage());
		}
	}

	public function testLinkTriggerFailuresOnUpdateAndDeleteAreReported(): void
	{
		$service = $this->service();
		$service->addLink($this->target, 'https://example.com/page', 'Page');
		$links = $this->links->forObject('user', (int) $this->object->id, (int) $this->context->conf->entity);
		self::assertCount(1, $links);
		$linkId = (int) $links[0]->id;

		RecordingTriggers::failOn('LINK_MODIFY', 'No change');
		$updated = $service->updateLink($this->target, $linkId, 'https://example.com/other', 'Other', false);
		RecordingTriggers::failOn('LINK_DELETE', 'No deletion');
		$deleted = $service->deleteLink($this->target, $linkId);

		self::assertSame([
			['type' => 'errors', 'text' => $this->text('ErrorFailedToUpdateLink', 'Other')],
			['type' => 'errors', 'text' => 'No change'],
		], $updated->messages);
		self::assertSame([['type' => 'errors', 'text' => 'No deletion']], $deleted->messages);
		$kept = $this->links->find($linkId);
		self::assertNotNull($kept);
		self::assertSame('https://example.com/page', $kept->url);
	}

	public function testActionResultIgnoresEmptyTextsAndRefusesUnknownTypes(): void
	{
		$result = new ActionResult();
		$result->add('warnings', '  ');
		$result->add('mesgs', ' Done ');
		self::assertSame([['type' => 'mesgs', 'text' => 'Done']], $result->messages);
		self::assertTrue($result->ok);

		$this->expectException(\InvalidArgumentException::class);
		$result->add('info', 'text');
	}

	/**
	 * A connection whose statements starting with $keyword fail with $message (the others run on the legacy driver).
	 */
	private function connectionFailingOn(string $keyword, string $message): Connection
	{
		return new Connection($this->context->db, new class(new LegacyDriver($this->context->db), $keyword, $message) implements Driver {
			public function __construct(private readonly Driver $inner, private readonly string $keyword, private readonly string $message)
			{
			}

			public function fetchAll(BoundStatement $statement): array
			{
				return $this->inner->fetchAll($statement);
			}

			public function execute(BoundStatement $statement): int
			{
				if (str_starts_with($statement->sql, $this->keyword)) {
					throw DatabaseException::fromStatement($statement, $this->message, 1213);
				}

				return $this->inner->execute($statement);
			}

			public function lastInsertId(string $table, string $column): int
			{
				return $this->inner->lastInsertId($table, $column);
			}

			public function placeholders(BoundStatement $statement): BoundStatement
			{
				return $this->inner->placeholders($statement);
			}
		});
	}

	private function orm(Connection $connection): Orm
	{
		$hydrator = new Hydrator();

		return new Orm(
			$connection,
			new MappingRegistry(),
			new LegacyEntityScope(),
			$hydrator,
			new Snapshots($hydrator),
			new LegacyExtraFieldCatalog($connection, new LegacyConfig($this->context), new Logger())
		);
	}

	private function filesOn(Connection $connection): EcmFileRepository
	{
		return (new EcmFileRepository($this->orm($connection)))->withoutEntityScope();
	}

	private function service(?ScriptedHooks $scripted = null, ?Connection $connection = null): AttachedFilesService
	{
		$files = $connection === null ? $this->files : $this->filesOn($connection);
		$hookManager = new \HookManager($this->context->db);
		if ($scripted !== null) {
			ScriptedHooks::register($hookManager, 'actionlinkedfiles', $scripted);
		}
		$hooks = new LegacyHooks($hookManager);
		$config = new LegacyConfig($this->context);
		$triggers = new LegacyTriggers($this->context);
		$objects = new LegacyFileObjects($this->context->db);
		$policy = new UploadPolicy();
		$storage = new FileStorage($hooks, new RenamingUploadedFileMover(), $policy);

		return new AttachedFilesService(
			$storage,
			new FileIndex($files, $connection ?? $this->connection, $triggers, $objects, $config, $this->context),
			new Thumbnails($storage),
			$policy,
			new LinkWriter($this->links, $this->connection, $triggers, $objects, $config, $this->context),
			$this->links,
			$files,
			$hooks,
			new LegacyTranslator($this->context->langs),
			$config
		);
	}

	/**
	 * The text of a message as the browser shows it (\Translate::trans() encodes the accents, the Translator does not),
	 * trimmed as setEventMessage() does.
	 */
	private function text(string $key, string ...$parameters): string
	{
		return trim(html_entity_decode($this->context->langs->trans($key, ...$parameters), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	/**
	 * What list_of_documents() does before listing: completeFileArrayWithDatabaseInfo() indexes the files of the
	 * directory the index does not know, with the entity of the object or the current one when empty.
	 */
	private function indexAsTheListing(\User $object): void
	{
		global $modulepart;

		$modulepart = 'user';
		$fileArray = dol_dir_list($this->directoryOf($object), 'files', 0, '', '(\.meta|_preview.*\.png)$', 'name', SORT_ASC, 1);
		completeFileArrayWithDatabaseInfo($fileArray, 'users/'.$object->id, $object);
	}

	private function testUser(): \User
	{
		$user = new \User($this->context->db);
		$user->id = random_int(900000000, 999999999);
		$user->entity = (int) $this->context->conf->entity;

		return $user;
	}

	private function directoryOf(\User $object): string
	{
		return DOL_DATA_ROOT.'/users/'.$object->id;
	}

	private function upload(string $name, string $content = 'content'): UploadedFile
	{
		$temporaryPath = sys_get_temp_dir().'/mvc-upload-'.bin2hex(random_bytes(4));
		file_put_contents($temporaryPath, $content);

		return new UploadedFile($name, $temporaryPath, 'text/plain', strlen($content), UPLOAD_ERR_OK);
	}

	private function legacyLink(\User $object, string $url): int
	{
		$link = new \Link($this->context->db);
		$link->entity = (int) $this->context->conf->entity;
		$link->url = $url;
		$link->objecttype = 'user';
		$link->objectid = (int) $object->id;
		$link->label = 'Other';
		$linkId = $link->create($GLOBALS['user']);
		self::assertGreaterThan(0, $linkId);

		return (int) $linkId;
	}

	private function removeTree(string $directory): void
	{
		if (!is_dir($directory)) {
			return;
		}
		foreach (glob($directory.'/{*,.[!.]*}', GLOB_BRACE) ?: [] as $entry) {
			if (is_dir($entry) && !is_link($entry)) {
				$this->removeTree($entry);
			} else {
				unlink($entry);
			}
		}
		rmdir($directory);
	}
}
