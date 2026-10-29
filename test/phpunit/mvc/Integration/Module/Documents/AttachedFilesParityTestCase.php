<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\Documents;

use Dolibarr\Core\Database\Connection;
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
use Dolibarr\Module\Documents\Service\ActionResult;
use Dolibarr\Module\Documents\Service\AttachedFilesService;
use Dolibarr\Module\Documents\Service\DocumentsTarget;
use Dolibarr\Module\Documents\Service\LinkWriter;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Tests\Integration\Fixture\RecordingTriggers;
use Dolibarr\Tests\Integration\Fixture\RenamingUploadedFileMover;
use PHPUnit\Framework\TestCase;

/**
 * AttachedFilesService against the legacy code of actions_linkedfiles.inc.php: each scenario runs the legacy calls
 * of an action on the documents of a first test \User (users/<id A>), then the service on a second one
 * (users/<id B>), and compares the files on disk (relative names and contents, thumbnails included), the rows of
 * llx_ecm_files and llx_links (without ids, dates, ref, the object, a share key reduced to its presence), the
 * triggers received (RecordingTriggers) and the messages ($_SESSION['dol_events'] for the legacy half, the
 * ActionResult for the new one).
 *
 * The legacy half never includes actions_linkedfiles.inc.php: it ends with header() and exit. It runs the exact
 * calls of the block under test. No test is wrapped in a transaction (a trigger failure rolls back a nested one):
 * tearDown removes the rows and the directories. A subclass gives the database: MariaDB and PostgreSQL.
 */
abstract class AttachedFilesParityTestCase extends TestCase
{
	private const CONTENT = 'The same content in both directories';

	private mixed $previousDatabase;

	private Connection $connection;

	private AttachedFilesService $service;

	private \User $legacyObject;

	private \User $newObject;

	private DocumentsTarget $target;

	abstract protected function database(): \DoliDB;

	protected function setUp(): void
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

		$this->previousDatabase = $GLOBALS['db'];
		$GLOBALS['db'] = $this->database();
		$context = LegacyContext::fromGlobals();
		foreach (['errors', 'link', 'other'] as $domain) {
			$context->langs->load($domain);
		}
		$this->connection = Connection::forLegacy($context->db);
		$hydrator = new Hydrator();
		$config = new LegacyConfig($context);
		$orm = new Orm(
			$this->connection,
			new MappingRegistry(),
			new LegacyEntityScope(),
			$hydrator,
			new Snapshots($hydrator),
			new LegacyExtraFieldCatalog($this->connection, $config, new Logger())
		);
		$files = (new EcmFileRepository($orm))->withoutEntityScope();
		$links = (new ExternalLinkRepository($orm))->withoutEntityScope();
		$triggers = new LegacyTriggers($context);
		$objects = new LegacyFileObjects($context->db);
		$translator = new LegacyTranslator($context->langs);
		$policy = new UploadPolicy();
		$storage = new FileStorage(new LegacyHooks($context->hookmanager), new RenamingUploadedFileMover(), $policy);
		$this->service = new AttachedFilesService(
			$storage,
			new FileIndex($files, $this->connection, $triggers, $objects, $config, $context),
			new Thumbnails($storage),
			$policy,
			new LinkWriter($links, $this->connection, $triggers, $objects, $config, $context),
			$links,
			$files,
			new LegacyHooks($context->hookmanager),
			$translator,
			$config
		);

		$this->legacyObject = $this->testUser($context->db);
		$this->newObject = $this->testUser($context->db);
		foreach ([$this->legacyObject, $this->newObject] as $object) {
			mkdir($this->directoryOf($object), 0777, true);
		}
		$this->target = new DocumentsTarget(self::userDefinition(), $this->newObject, $this->directoryOf($this->newObject), 'user', true);
		RecordingTriggers::install();
		$_SESSION['dol_events'] = [];
	}

	protected function tearDown(): void
	{
		RecordingTriggers::uninstall();
		unset($_SESSION['dol_events']);
		foreach ([$this->legacyObject, $this->newObject] as $object) {
			$relativeDirectory = $this->relativeDirectoryOf($object);
			$this->connection->execute(
				'DELETE FROM @ecm_files_extrafields WHERE fk_object IN (SELECT rowid FROM @ecm_files WHERE filepath = :filepath)',
				['filepath' => $relativeDirectory]
			);
			$this->connection->execute('DELETE FROM @ecm_files WHERE filepath = :filepath', ['filepath' => $relativeDirectory]);
			$this->connection->execute(
				'DELETE FROM @links WHERE objecttype = :objecttype AND objectid = :objectid',
				['objecttype' => 'user', 'objectid' => (int) $object->id]
			);
			$this->removeTree($this->directoryOf($object));
		}
		$GLOBALS['db'] = $this->previousDatabase;
	}

	public function testDeleteFileMatchesLegacy(): void
	{
		$this->createImageInBoth('photo.png');
		$this->legacyIndex($this->legacyObject, 'photo.png');
		$this->legacyIndex($this->newObject, 'photo.png');
		$this->legacyObject->addThumbs($this->directoryOf($this->legacyObject).'/photo.png');
		$this->newObject->addThumbs($this->directoryOf($this->newObject).'/photo.png');
		self::assertFileExists($this->directoryOf($this->newObject).'/thumbs/photo_small.png');
		RecordingTriggers::forget();

		$this->legacyDeleteFile($this->legacyObject, 'photo.png');
		$legacyCalls = RecordingTriggers::forget();
		$legacyMessages = $this->legacyMessages();
		$result = $this->service->deleteFile($this->target, 'photo.png');
		$newCalls = RecordingTriggers::forget();

		self::assertSame(['ECMFILES_DELETE'], array_column($newCalls, 'action'));
		self::assertFileDoesNotExist($this->directoryOf($this->newObject).'/photo.png');
		self::assertFileDoesNotExist($this->directoryOf($this->newObject).'/thumbs/photo_small.png');
		$this->assertSameState($legacyCalls, $newCalls);
		$this->assertSameMessages($legacyMessages, $result);
		self::assertTrue($result->ok);
	}

	public function testDeleteFileOfAMissingFileMatchesLegacy(): void
	{
		$this->legacyDeleteFile($this->legacyObject, 'missing.txt');
		$legacyMessages = $this->legacyMessages();
		$result = $this->service->deleteFile($this->target, 'missing.txt');

		$this->assertSameMessages($legacyMessages, $result);
		self::assertFalse($result->ok);
	}

	public function testRenameMatchesLegacy(): void
	{
		$this->createImageInBoth('photo.png');
		$this->legacyIndex($this->legacyObject, 'photo.png');
		$this->legacyIndex($this->newObject, 'photo.png');
		$this->legacyObject->addThumbs($this->directoryOf($this->legacyObject).'/photo.png');
		$this->newObject->addThumbs($this->directoryOf($this->newObject).'/photo.png');
		RecordingTriggers::forget();

		$this->legacyRename($this->legacyObject, 'photo.png', 'holiday.png');
		$legacyCalls = RecordingTriggers::forget();
		$legacyMessages = $this->legacyMessages();
		$result = $this->service->rename($this->target, 'photo.png', 'holiday.png');
		$newCalls = RecordingTriggers::forget();

		self::assertSame(['ECMFILES_MODIFY'], array_column($newCalls, 'action'));
		self::assertFileExists($this->directoryOf($this->newObject).'/holiday.png');
		self::assertFileExists($this->directoryOf($this->newObject).'/thumbs/holiday_mini.png');
		self::assertFileDoesNotExist($this->directoryOf($this->newObject).'/thumbs/photo_mini.png');
		$this->assertSameState($legacyCalls, $newCalls);
		$this->assertSameMessages($legacyMessages, $result);
	}

	public function testRenameOntoAnExistingFileMatchesLegacy(): void
	{
		$this->createTextInBoth('first.txt');
		$this->createTextInBoth('second.txt');

		$this->legacyRename($this->legacyObject, 'first.txt', 'second.txt');
		$legacyMessages = $this->legacyMessages();
		$result = $this->service->rename($this->target, 'first.txt', 'second.txt');

		$this->assertSameState([], []);
		$this->assertSameMessages($legacyMessages, $result);
		self::assertFalse($result->ok);
	}

	public function testShareMatchesLegacy(): void
	{
		$this->createTextInBoth('shared.txt');
		$legacyId = $this->legacyIndex($this->legacyObject, 'shared.txt');
		$newId = $this->legacyIndex($this->newObject, 'shared.txt');
		RecordingTriggers::forget();

		foreach ([true, true, false] as $step => $enabled) {
			$this->legacyShare($legacyId, $enabled);
			$legacyCalls = RecordingTriggers::forget();
			$legacyMessages = $this->legacyMessages();
			$result = $this->service->share($this->target, $newId, $enabled);
			$newCalls = RecordingTriggers::forget();

			self::assertSame(['ECMFILES_MODIFY'], array_column($newCalls, 'action'), 'step '.$step);
			$this->assertSameState($legacyCalls, $newCalls);
			$this->assertSameMessages($legacyMessages, $result);
		}
	}

	public function testLinksMatchLegacy(): void
	{
		$this->legacyAddLink($this->legacyObject, 'www.example.com/docs/guide.html', '');
		$legacyCalls = RecordingTriggers::forget();
		$legacyMessages = $this->legacyMessages();
		$result = $this->service->addLink($this->target, 'www.example.com/docs/guide.html', '');
		$newCalls = RecordingTriggers::forget();

		self::assertSame(['LINK_CREATE'], array_column($newCalls, 'action'));
		$this->assertSameState($legacyCalls, $newCalls);
		$this->assertSameMessages($legacyMessages, $result);

		foreach ([['https://WWW.Example.COM/manual', 'Manual', true], ['ftp.example.org/file', '', false]] as $step => [$url, $label, $share]) {
			$this->legacyUpdateLink($this->legacyObject, $this->linkId($this->legacyObject), $url, $label, $share);
			$legacyCalls = RecordingTriggers::forget();
			$legacyMessages = $this->legacyMessages();
			$result = $this->service->updateLink($this->target, $this->linkId($this->newObject), $url, $label, $share);
			$newCalls = RecordingTriggers::forget();

			self::assertSame(['LINK_MODIFY'], array_column($newCalls, 'action'), 'step '.$step);
			$this->assertSameState($legacyCalls, $newCalls);
			$this->assertSameMessages($legacyMessages, $result);
		}

		$this->legacyDeleteLink($this->linkId($this->legacyObject));
		$legacyCalls = RecordingTriggers::forget();
		$legacyMessages = $this->legacyMessages();
		$result = $this->service->deleteLink($this->target, $this->linkId($this->newObject));
		$newCalls = RecordingTriggers::forget();

		self::assertSame(['LINK_DELETE'], array_column($newCalls, 'action'));
		$this->assertSameState($legacyCalls, $newCalls);
		$this->assertSameMessages($legacyMessages, $result);
		self::assertSame(0, $this->countLinks($this->newObject));
	}

	public function testRefusedSvgLinkMatchesLegacy(): void
	{
		$this->legacyAddLink($this->legacyObject, 'https://example.com/logo.SVG', 'Logo');
		$legacyMessages = $this->legacyMessages();
		$result = $this->service->addLink($this->target, 'https://example.com/logo.SVG', 'Logo');

		$this->assertSameState([], []);
		$this->assertSameMessages($legacyMessages, $result);
		self::assertSame(0, $this->countLinks($this->newObject));
	}

	public function testUploadIndexMatchesLegacy(): void
	{
		// R7: move_uploaded_file() does not run in the CLI; the legacy half indexes a file already in place
		file_put_contents($this->directoryOf($this->legacyObject).'/Report.txt', self::CONTENT);
		addFileIntoDatabaseIndex($this->directoryOf($this->legacyObject), 'Report.txt', 'Report.TXT', 'uploaded', 0, $this->legacyObject);
		$legacyCalls = RecordingTriggers::forget();
		$result = $this->service->upload($this->target, [$this->upload('Report.TXT', self::CONTENT)], false, '');
		$newCalls = RecordingTriggers::forget();

		self::assertSame(['ECMFILES_CREATE'], array_column($newCalls, 'action'));
		$this->assertSameState($legacyCalls, $newCalls);
		self::assertSame([['type' => 'mesgs', 'text' => self::rendered($GLOBALS['langs']->trans('FileTransferComplete'))]], $result->messages);
	}

	/**
	 * actions_linkedfiles.inc.php l.137-183 for a file of the documents page (no section, no old directory).
	 */
	private function legacyDeleteFile(\User $object, string $urlfile): void
	{
		global $langs;

		$upload_dir = $this->directoryOf($object);
		$urlfile = basename($urlfile);
		$file = $upload_dir.(preg_match('/\/$/', $upload_dir) ? '' : '/').$urlfile;
		$dir = dirname($file).'/';
		$dirthumb = $dir.'/thumbs/';

		// The legacy unlink() warns about a missing file (nophperrors = 0)
		$ret = @dol_delete_file($file, 1, 0, 0, $object);
		if ($ret) {
			$regs = [];
			if (preg_match('/(\.jpg|\.jpeg|\.bmp|\.gif|\.png|\.tiff|\.webp|\.xpm|\.xbm|\.avif)$/i', $file, $regs)) {
				$photo_vignette = basename(preg_replace('/'.$regs[0].'/i', '', $file).'_small'.$regs[0]);
				if (file_exists(dol_osencode($dirthumb.$photo_vignette))) {
					dol_delete_file($dirthumb.$photo_vignette);
				}
				$photo_vignette = basename(preg_replace('/'.$regs[0].'/i', '', $file).'_mini'.$regs[0]);
				if (file_exists(dol_osencode($dirthumb.$photo_vignette))) {
					dol_delete_file($dirthumb.$photo_vignette);
				}
			}
			setEventMessages($langs->trans('FileWasRemoved', $urlfile), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('ErrorFailToDeleteFile', $urlfile), null, 'errors');
		}
	}

	/**
	 * actions_linkedfiles.inc.php l.246-353 for names without __, reserved command or executable extension.
	 */
	private function legacyRename(\User $object, string $from, string $to): void
	{
		global $langs;

		$upload_dir = $this->directoryOf($object);
		$filenamefrom = dol_sanitizeFileName($from, '_', 0);
		$filenameto = dol_string_nohtmltag(dol_sanitizeFileName($to, '_', 0));
		$srcpath = $upload_dir.'/'.$filenamefrom;
		$destpath = $upload_dir.'/'.$filenameto;
		if (!file_exists($destpath)) {
			$result = dol_move($srcpath, $destpath, '0', 1, 0, 1, [], $object->entity);
			if ($result) {
				$object->addThumbs($destpath);
				$object->delThumbs($srcpath);
				setEventMessages($langs->trans('FileRenamed'), null);
			} else {
				setEventMessages($langs->trans('ErrorFailToRenameFile', $filenamefrom, $filenameto), null, 'errors');
			}
		} else {
			setEventMessages($langs->trans('ErrorDestinationAlreadyExists', $filenameto), null, 'errors');
		}
	}

	/**
	 * actions_linkedfiles.inc.php l.355-374.
	 */
	private function legacyShare(int $ecmFileId, bool $enabled): void
	{
		$ecmfile = new \EcmFiles($this->database());
		$result = $ecmfile->fetch($ecmFileId);
		if ($result > 0) {
			if ($enabled) {
				if (empty($ecmfile->share)) {
					$ecmfile->share = getRandomPassword(true);
				}
			} else {
				$ecmfile->share = '';
			}
			$result = $ecmfile->update($GLOBALS['user']);
			if ($result < 0) {
				setEventMessages($ecmfile->error, $ecmfile->errors, 'warnings');
			}
		}
	}

	/**
	 * actions_linkedfiles.inc.php l.106-133, then dol_add_file_process() l.2291-2305 with the object of the page.
	 */
	private function legacyAddLink(\User $object, string $link, string $label): void
	{
		global $langs, $conf;

		$error = 0;
		if (substr($link, 0, 7) != 'http://' && substr($link, 0, 8) != 'https://' && substr($link, 0, 7) != 'davs://') {
			$link = 'http://'.$link;
		}
		$newUrlArray = parse_url($link);
		if (!empty($newUrlArray['path']) && preg_match('/\.svg$/i', $newUrlArray['path'])) {
			$error++;
			setEventMessages($langs->trans('ErrorSVGFilesNotAllowedAsLinksWithout', 'MAIN_ALLOW_SVG_FILES_AS_EXTERNAL_LINKS'), null, 'errors');
		}
		if (!$error) {
			$linkObject = new \Link($this->database());
			$linkObject->entity = $conf->entity;
			$linkObject->url = $link;
			$linkObject->objecttype = $object->element;
			$linkObject->objectid = $object->id;
			$linkObject->label = $label;
			$res = $linkObject->create($GLOBALS['user']);
			if ($res > 0) {
				setEventMessages($langs->trans('LinkComplete'), null, 'mesgs');
			} else {
				setEventMessages($langs->trans('ErrorFileNotLinked'), null, 'errors');
			}
		}
	}

	/**
	 * actions_linkedfiles.inc.php l.206-245.
	 */
	private function legacyUpdateLink(\User $object, int $linkId, string $url, string $label, bool $share): void
	{
		global $langs;

		$link = new \Link($this->database());
		self::assertGreaterThan(0, $link->fetch($linkId));
		self::assertSame($object->element, $link->objecttype);
		$link->url = $url;
		if (substr($link->url, 0, 7) != 'http://' && substr($link->url, 0, 8) != 'https://' && substr($link->url, 0, 7) != 'davs://') {
			$link->url = 'http://'.$link->url;
		}
		$link->label = $label;
		$link->share = $share ? getRandomPassword(true) : '';
		$res = $link->update($GLOBALS['user']);
		if ($res <= 0) {
			setEventMessages($langs->trans('ErrorFailedToUpdateLink', $link->label), $link->errors, 'errors');
		}
	}

	/**
	 * actions_linkedfiles.inc.php l.184-205.
	 */
	private function legacyDeleteLink(int $linkId): void
	{
		global $langs;

		$link = new \Link($this->database());
		$link->fetch($linkId);
		$res = $link->delete($GLOBALS['user']);
		if ($res > 0) {
			setEventMessages($langs->trans('LinkRemoved', $link->label), null, 'mesgs');
		} elseif (count($link->errors)) {
			setEventMessages('', $link->errors, 'errors');
		} else {
			setEventMessages($langs->trans('ErrorFailedToDeleteLink', $link->label), null, 'errors');
		}
	}

	private function testUser(\DoliDB $database): \User
	{
		$user = new \User($database);
		$user->id = random_int(900000000, 999999999);
		$user->entity = (int) $GLOBALS['conf']->entity;

		return $user;
	}

	private static function userDefinition(): EntityDefinition
	{
		return new EntityDefinition('user', User::class, UserRepository::class, 'user', 'user', UserPermissionChecker::class, UserCardChrome::class, 'user_show', \User::class, '/user/class/user.class.php');
	}

	private function relativeDirectoryOf(\User $object): string
	{
		return 'users/'.$object->id;
	}

	private function directoryOf(\User $object): string
	{
		return DOL_DATA_ROOT.'/'.$this->relativeDirectoryOf($object);
	}

	private function createTextInBoth(string $name): void
	{
		foreach ([$this->legacyObject, $this->newObject] as $object) {
			file_put_contents($this->directoryOf($object).'/'.$name, self::CONTENT);
		}
	}

	private function createImageInBoth(string $name): void
	{
		$image = imagecreatetruecolor(600, 400);
		self::assertNotFalse($image);
		foreach ([$this->legacyObject, $this->newObject] as $object) {
			imagepng($image, $this->directoryOf($object).'/'.$name);
		}
	}

	private function upload(string $name, string $content): UploadedFile
	{
		$temporaryPath = sys_get_temp_dir().'/mvc-upload-'.bin2hex(random_bytes(4));
		file_put_contents($temporaryPath, $content);

		return new UploadedFile($name, $temporaryPath, 'text/plain', strlen($content), UPLOAD_ERR_OK);
	}

	/**
	 * Indexes a file of the object with the legacy function and returns the id of its row.
	 */
	private function legacyIndex(\User $object, string $name): int
	{
		addFileIntoDatabaseIndex($this->directoryOf($object), $name, $name, 'uploaded', 0, $object);
		$identifier = $this->connection->fetchValue(
			'SELECT MAX(rowid) FROM @ecm_files WHERE filepath = :filepath AND filename = :filename',
			['filepath' => $this->relativeDirectoryOf($object), 'filename' => $name]
		);
		self::assertNotNull($identifier);

		return (int) $identifier;
	}

	private function linkId(\User $object): int
	{
		$identifier = $this->connection->fetchValue(
			'SELECT MAX(rowid) FROM @links WHERE objecttype = :objecttype AND objectid = :objectid',
			['objecttype' => 'user', 'objectid' => (int) $object->id]
		);
		self::assertNotNull($identifier);

		return (int) $identifier;
	}

	private function countLinks(\User $object): int
	{
		return (int) $this->connection->fetchValue(
			'SELECT COUNT(*) FROM @links WHERE objecttype = :objecttype AND objectid = :objectid',
			['objecttype' => 'user', 'objectid' => (int) $object->id]
		);
	}

	/**
	 * The messages setEventMessages() stacked since the last call, which empties the stack.
	 *
	 * @return array<string, list<string>>
	 */
	private function legacyMessages(): array
	{
		$messages = [];
		foreach (($_SESSION['dol_events'] ?? []) as $type => $texts) {
			if ($texts !== []) {
				$messages[(string) $type] = array_values(array_map(static fn (mixed $text): string => self::rendered((string) $text), $texts));
			}
		}
		$_SESSION['dol_events'] = [];
		ksort($messages);

		return $messages;
	}

	/**
	 * The text a browser shows: \Translate::trans() encodes the accents as HTML entities, the Translator of the new
	 * code keeps them as UTF-8 (the arguments are escaped by transHtml()).
	 */
	private static function rendered(string $html): string
	{
		return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/**
	 * @param array<string, list<string>> $legacyMessages
	 */
	private function assertSameMessages(array $legacyMessages, ActionResult $result): void
	{
		$newMessages = [];
		foreach ($result->messages as $message) {
			$newMessages[$message['type']][] = self::rendered($message['text']);
		}
		ksort($newMessages);

		self::assertSame($legacyMessages, $newMessages);
	}

	/**
	 * Same files, rows and triggers in both directories.
	 *
	 * @param list<array<string, mixed>> $legacyCalls
	 * @param list<array<string, mixed>> $newCalls
	 */
	private function assertSameState(array $legacyCalls, array $newCalls): void
	{
		self::assertSame($this->filesOf($this->legacyObject), $this->filesOf($this->newObject), 'files on disk');
		self::assertSame($this->indexRowsOf($this->legacyObject), $this->indexRowsOf($this->newObject), 'llx_ecm_files');
		self::assertSame($this->linkRowsOf($this->legacyObject), $this->linkRowsOf($this->newObject), 'llx_links');
		self::assertSame($this->comparableCalls($legacyCalls, $this->legacyObject), $this->comparableCalls($newCalls, $this->newObject), 'triggers');
	}

	/**
	 * @return array<string, string> Relative name => md5 of the content
	 */
	private function filesOf(\User $object): array
	{
		$files = [];
		$directory = $this->directoryOf($object);
		foreach ([$directory, $directory.'/thumbs'] as $scanned) {
			foreach (glob($scanned.'/*') ?: [] as $path) {
				if (is_file($path)) {
					$files[substr($path, strlen($directory) + 1)] = (string) md5_file($path);
				}
			}
		}
		ksort($files);

		return $files;
	}

	/**
	 * @return list<array<string, ?string>>
	 */
	private function indexRowsOf(\User $object): array
	{
		$rows = $this->connection->fetchAll(
			'SELECT * FROM @ecm_files WHERE filepath = :filepath ORDER BY filename',
			['filepath' => $this->relativeDirectoryOf($object)]
		);
		$comparable = [];
		foreach ($rows as $row) {
			$comparable[] = $this->comparableRow($row, $object, ['rowid', 'ref', 'date_c', 'tms', 'src_object_id']);
		}

		return $comparable;
	}

	/**
	 * @return list<array<string, ?string>>
	 */
	private function linkRowsOf(\User $object): array
	{
		$rows = $this->connection->fetchAll(
			'SELECT * FROM @links WHERE objecttype = :objecttype AND objectid = :objectid ORDER BY label',
			['objecttype' => 'user', 'objectid' => (int) $object->id]
		);
		$comparable = [];
		foreach ($rows as $row) {
			$comparable[] = $this->comparableRow($row, $object, ['rowid', 'datea', 'objectid']);
		}

		return $comparable;
	}

	/**
	 * @param array<string|int, mixed> $row
	 * @param list<string>             $volatileColumns
	 * @return array<string, ?string>
	 */
	private function comparableRow(array $row, \User $object, array $volatileColumns): array
	{
		foreach ($volatileColumns as $volatile) {
			unset($row[$volatile]);
		}
		$comparable = [];
		foreach ($row as $column => $value) {
			$comparable[(string) $column] = $value === null ? null : $this->anonymize((string) $value, $object);
		}
		foreach (['share', 'share_pass'] as $secret) {
			if (array_key_exists($secret, $comparable)) {
				$comparable[$secret] = $this->shareState($comparable[$secret]);
			}
		}

		return $comparable;
	}

	/**
	 * @param list<array<string, mixed>> $calls
	 * @return list<array<string, mixed>>
	 */
	private function comparableCalls(array $calls, \User $object): array
	{
		$comparable = [];
		foreach ($calls as $call) {
			$properties = $call['object'];
			self::assertIsArray($properties);
			// The legacy trigger gets the object it just wrote (fk_user_c, fk_user_m and objectid as set), the new one the row read back
			unset($properties['id'], $properties['fk_user_c'], $properties['fk_user_m'], $properties['src_object_id'], $properties['objectid']);
			$call['object'] = [];
			foreach ($properties as $property => $value) {
				$call['object'][$property] = $value === null ? null : $this->anonymize((string) $value, $object);
			}
			$call['object']['share'] = $this->shareState($properties['share'] ?? null);
			$comparable[] = $call;
		}

		return $comparable;
	}

	private function shareState(mixed $share): string
	{
		return $share === null || $share === '' ? 'none' : 'key';
	}

	private function anonymize(string $value, \User $object): string
	{
		return str_replace($this->relativeDirectoryOf($object), '{directory}', $value);
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
