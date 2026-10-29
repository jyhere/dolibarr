<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Files;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Files\EcmFile;
use Dolibarr\Core\Files\EcmFileRepository;
use Dolibarr\Core\Files\FileIndex;
use Dolibarr\Core\Files\FileTriggerException;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyEntityScope;
use Dolibarr\Core\Legacy\LegacyExtraFieldCatalog;
use Dolibarr\Core\Legacy\LegacyFileObjects;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Logger;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Core\Orm\Snapshots;
use Dolibarr\Tests\Integration\Fixture\RecordingTriggers;
use PHPUnit\Framework\TestCase;

/**
 * FileIndex against the legacy functions that write llx_ecm_files (addFileIntoDatabaseIndex, dol_delete_file,
 * dol_move, deleteFilesIntoDatabaseIndex, \EcmFiles::update): each scenario runs the legacy function on a file,
 * then the new code on a twin file of the same directory and content, and compares the rows (without what
 * differs by design: rowid, ref and filename depend on the name, dates on the clock, positions follow each
 * other, a share key is only compared as present or absent) and the ECMFILES_* triggers received (RecordingTriggers).
 *
 * No test is wrapped in a transaction: a trigger failure rolls back a transaction nested in the test's one
 * for nothing (DoliDB counts the levels), so the rows are removed by tearDown instead.
 * A subclass gives the database: MariaDB (Integration) and PostgreSQL (Pgsql, the legacy globals are pointed to it).
 */
abstract class FileIndexParityTestCase extends TestCase
{
	private const CONTENT = 'The same content in both twins';

	private mixed $previousDatabase;

	private Connection $connection;

	private EcmFileRepository $files;

	private FileIndex $index;

	private string $directory;

	private string $relativeDirectory;

	private int $entity;

	abstract protected function database(): \DoliDB;

	protected function setUp(): void
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

		$this->previousDatabase = $GLOBALS['db'];
		$GLOBALS['db'] = $this->database();
		$context = LegacyContext::fromGlobals();
		$this->connection = Connection::forLegacy($context->db);
		$hydrator = new Hydrator();
		$orm = new Orm(
			$this->connection,
			new MappingRegistry(),
			new LegacyEntityScope(),
			$hydrator,
			new Snapshots($hydrator),
			new LegacyExtraFieldCatalog($this->connection, new LegacyConfig($context), new Logger())
		);
		$this->files = (new EcmFileRepository($orm))->withoutEntityScope();
		$this->index = new FileIndex(
			$this->files,
			$this->connection,
			new LegacyTriggers($context),
			new LegacyFileObjects($context->db),
			new LegacyConfig($context),
			$context
		);
		$this->entity = (int) $context->conf->entity;
		$this->relativeDirectory = 'users/mvc-parity-'.bin2hex(random_bytes(4));
		$this->directory = DOL_DATA_ROOT.'/'.$this->relativeDirectory;
		mkdir($this->directory, 0777, true);
		RecordingTriggers::install();
	}

	protected function tearDown(): void
	{
		RecordingTriggers::uninstall();
		$this->connection->execute(
			'DELETE FROM @ecm_files_extrafields WHERE fk_object IN (SELECT rowid FROM @ecm_files WHERE filepath LIKE :filepath)',
			['filepath' => $this->relativeDirectory.'%']
		);
		$this->connection->execute('DELETE FROM @ecm_files WHERE filepath LIKE :filepath', ['filepath' => $this->relativeDirectory.'%']);
		foreach ([$this->directory.'/thumbs', $this->directory.'/temp', $this->directory] as $directory) {
			foreach (glob($directory.'/*') ?: [] as $path) {
				if (is_file($path)) {
					unlink($path);
				}
			}
			if (is_dir($directory)) {
				rmdir($directory);
			}
		}
		$GLOBALS['db'] = $this->previousDatabase;
	}

	public function testAddMatchesAddFileIntoDatabaseIndex(): void
	{
		$source = $this->legacyUser();
		$this->createTwins('legacy-add.txt', 'new-add.txt');

		addFileIntoDatabaseIndex($this->directory, 'legacy-add.txt', 'Original.txt', 'uploaded', 0, $source);
		$legacyCalls = RecordingTriggers::forget();
		$created = $this->index->add($this->directory, 'new-add.txt', 'Original.txt', $source, false);
		$newCalls = RecordingTriggers::forget();

		self::assertInstanceOf(EcmFile::class, $created);
		self::assertIsInt($created->id);
		self::assertSame(['ECMFILES_CREATE'], array_column($newCalls, 'action'));
		$this->assertSameRows(['legacy-add.txt'], ['new-add.txt']);
		$this->assertSameCalls($legacyCalls, $newCalls, ['legacy-add.txt'], ['new-add.txt']);
		self::assertSame($this->position('legacy-add.txt') + 1, $this->position('new-add.txt'));
		self::assertSame($this->relativeDirectory, $created->filepath);
		self::assertSame(md5(self::CONTENT), $created->label);
		self::assertSame($source->table_element, $created->srcObjectType);
		self::assertSame((int) $source->id, $created->srcObjectId);
		self::assertSame((int) $GLOBALS['user']->id, $created->createdBy);
		self::assertNull($created->modifiedBy);
		self::assertSame('', $created->description);
		self::assertSame('', $created->keywords);
		self::assertSame('uploaded', $created->genOrUploaded);
		self::assertNotNull($created->createdAt);
		self::assertSame(dol_hash($this->relativeDirectory.'/new-add.txt', '3'), $created->ref);
	}

	public function testAddWithoutSourceSharesAndStripsNoexeLikeTheLegacy(): void
	{
		$this->createTwins('legacy-shared.php.noexe', 'new-shared.php.noexe');

		addFileIntoDatabaseIndex($this->directory, 'legacy-shared.php.noexe', 'shared.php', 'uploaded', 1);
		$legacyCalls = RecordingTriggers::forget();
		$created = $this->index->add($this->directory, 'new-shared.php.noexe', 'shared.php', null, true);
		$newCalls = RecordingTriggers::forget();

		self::assertInstanceOf(EcmFile::class, $created);
		self::assertSame('new-shared.php', $created->filename);
		self::assertSame(32, strlen((string) $created->share));
		self::assertSame($this->entity, $created->entity);
		self::assertNull($created->srcObjectType);
		self::assertNull($created->srcObjectId);
		$this->assertSameRows(['legacy-shared.php'], ['new-shared.php']);
		$this->assertSameCalls($legacyCalls, $newCalls, ['legacy-shared.php'], ['new-shared.php']);
	}

	public function testAddIgnoresThumbsAndTempDirectories(): void
	{
		foreach (['/thumbs', '/temp/', '/folder.meta'] as $suffix) {
			$directory = $this->directory.$suffix;
			@mkdir($directory, 0777, true);
			file_put_contents($directory.'/file.txt', self::CONTENT);

			self::assertSame(0, addFileIntoDatabaseIndex($directory, 'file.txt', 'Original.txt'), $suffix);
			self::assertNull($this->index->add($directory, 'file.txt', 'Original.txt', null, false), $suffix);
			unlink($directory.'/file.txt');
			@rmdir($directory);
		}

		self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @ecm_files WHERE filepath LIKE :filepath', ['filepath' => $this->relativeDirectory.'%']));
		self::assertSame([], RecordingTriggers::calls());
	}

	public function testRemoveFileMatchesDolDeleteFile(): void
	{
		$this->createTwins('legacy-del.txt', 'new-del.txt');
		$legacyId = $this->legacyIndex('legacy-del.txt');
		$newId = $this->legacyIndex('new-del.txt');
		foreach ([$legacyId, $newId] as $id) {
			$this->connection->execute('INSERT INTO @ecm_files_extrafields (fk_object) VALUES (:id)', ['id' => $id]);
		}
		RecordingTriggers::forget();

		self::assertTrue(dol_delete_file($this->directory.'/legacy-del.txt', 0, 0, 0, null, false, 1, 1));
		$legacyCalls = RecordingTriggers::forget();
		unlink($this->directory.'/new-del.txt');
		$this->index->removeFile($this->directory.'/new-del.txt', $this->entity);
		$newCalls = RecordingTriggers::forget();

		foreach ([$legacyId, $newId] as $id) {
			self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @ecm_files WHERE rowid = :id', ['id' => $id]));
			self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @ecm_files_extrafields WHERE fk_object = :id', ['id' => $id]));
		}
		self::assertSame(['ECMFILES_DELETE'], array_column($newCalls, 'action'));
		$this->assertSameCalls($legacyCalls, $newCalls, ['legacy-del.txt'], ['new-del.txt']);
		$deleted = $newCalls[0]['object'];
		self::assertIsArray($deleted);
		self::assertSame($newId, (int) $deleted['id'], 'the trigger receives the object as loaded before the deletion');
	}

	public function testRemoveFileLeavesTheIndexOfTempAndThumbsPathsAndUnknownFiles(): void
	{
		$thumb = $this->files->save(new EcmFile(null, null, 'hash', null, $this->entity, $this->relativeDirectory.'/thumbs', 'small.png'));
		$this->index->removeFile($this->directory.'/thumbs/small.png', $this->entity);
		$this->index->removeFile($this->directory.'/not-indexed.txt', $this->entity);

		self::assertNotNull($this->files->find((int) $thumb->id));
		self::assertSame([], RecordingTriggers::calls());
	}

	public function testRemoveIndexRowMatchesDeleteFilesIntoDatabaseIndexWithoutTrigger(): void
	{
		$this->createTwins('legacy-row.txt', 'new-row.txt');
		$this->legacyIndex('legacy-row.txt');
		$this->legacyIndex('new-row.txt');
		$this->legacyIndex('legacy-row.txt');
		RecordingTriggers::forget();

		self::assertSame(1, deleteFilesIntoDatabaseIndex($this->directory, 'legacy-row.txt', ''));
		$this->index->removeIndexRow($this->directory, 'new-row.txt', $this->entity);

		self::assertSame(0, $this->countRows('legacy-row.txt'));
		self::assertSame(0, $this->countRows('new-row.txt'));
		self::assertSame([], RecordingTriggers::calls());
	}

	public function testRenameMatchesDolMoveWithAnIndexedSource(): void
	{
		$this->createTwins('from-legacy.txt', 'from-new.txt');
		$this->legacyIndex('from-legacy.txt');
		$this->legacyIndex('from-new.txt');
		RecordingTriggers::forget();

		self::assertTrue(dol_move($this->directory.'/from-legacy.txt', $this->directory.'/to-legacy.txt'));
		$legacyCalls = RecordingTriggers::forget();
		rename($this->directory.'/from-new.txt', $this->directory.'/to-new.txt');
		$this->index->rename($this->directory.'/from-new.txt', $this->directory.'/to-new.txt', $this->entity);
		$newCalls = RecordingTriggers::forget();

		self::assertSame(0, $this->countRows('from-legacy.txt') + $this->countRows('from-new.txt'));
		self::assertSame(['ECMFILES_MODIFY'], array_column($newCalls, 'action'));
		$this->assertSameRows(['to-legacy.txt', 'from-legacy.txt'], ['to-new.txt', 'from-new.txt']);
		$this->assertSameCalls($legacyCalls, $newCalls, ['from-legacy.txt', 'to-legacy.txt'], ['from-new.txt', 'to-new.txt']);
		$renamed = $this->files->findByPath($this->entity, $this->relativeDirectory, 'to-new.txt');
		self::assertNotNull($renamed);
		self::assertSame(dol_hash($this->relativeDirectory.'/to-new.txt', '3'), $renamed->ref);
		self::assertSame((int) $GLOBALS['user']->id, $renamed->modifiedBy);
	}

	public function testRenameCreatesTheRowOfAnUnindexedSource(): void
	{
		$this->createTwins('plain-legacy.txt', 'plain-new.txt');

		self::assertTrue(dol_move($this->directory.'/plain-legacy.txt', $this->directory.'/moved-legacy.txt'));
		$legacyCalls = RecordingTriggers::forget();
		rename($this->directory.'/plain-new.txt', $this->directory.'/moved-new.txt');
		$this->index->rename($this->directory.'/plain-new.txt', $this->directory.'/moved-new.txt', $this->entity);
		$newCalls = RecordingTriggers::forget();

		self::assertSame(['ECMFILES_CREATE'], array_column($newCalls, 'action'));
		$this->assertSameRows(['moved-legacy.txt', 'plain-legacy.txt'], ['moved-new.txt', 'plain-new.txt']);
		$this->assertSameCalls($legacyCalls, $newCalls, ['plain-legacy.txt', 'moved-legacy.txt'], ['plain-new.txt', 'moved-new.txt']);
		$created = $this->files->findByPath($this->entity, $this->relativeDirectory, 'moved-new.txt');
		self::assertNotNull($created);
		self::assertSame('unknown', $created->genOrUploaded);
		self::assertSame('plain-new.txt', $created->fullpathOrig);
		self::assertSame(md5(self::CONTENT), $created->label);
		self::assertSame('', $created->description);
		self::assertSame('', $created->keywords);
		self::assertSame($this->position('moved-legacy.txt') + 1, $created->position);
	}

	public function testRenameOverAnIndexedTargetReplacesItsRow(): void
	{
		$this->createTwins('src-legacy.txt', 'src-new.txt');
		$this->createTwins('dst-legacy.txt', 'dst-new.txt');
		foreach (['src-legacy.txt', 'dst-legacy.txt', 'src-new.txt', 'dst-new.txt'] as $name) {
			$this->legacyIndex($name);
		}
		RecordingTriggers::forget();

		self::assertTrue(dol_move($this->directory.'/src-legacy.txt', $this->directory.'/dst-legacy.txt'));
		$legacyCalls = RecordingTriggers::forget();
		rename($this->directory.'/src-new.txt', $this->directory.'/dst-new.txt');
		$this->index->rename($this->directory.'/src-new.txt', $this->directory.'/dst-new.txt', $this->entity);
		$newCalls = RecordingTriggers::forget();

		self::assertSame(['ECMFILES_DELETE', 'ECMFILES_MODIFY'], array_column($newCalls, 'action'));
		self::assertSame(1, $this->countRows('dst-new.txt'));
		self::assertSame(0, $this->countRows('src-new.txt'));
		$this->assertSameRows(['dst-legacy.txt', 'src-legacy.txt'], ['dst-new.txt', 'src-new.txt']);
		$this->assertSameCalls($legacyCalls, $newCalls, ['src-legacy.txt', 'dst-legacy.txt'], ['src-new.txt', 'dst-new.txt']);
	}

	public function testSetSharedMatchesEcmFilesUpdate(): void
	{
		$this->createTwins('share-legacy.txt', 'share-new.txt');
		$legacyId = $this->legacyIndex('share-legacy.txt');
		$newId = $this->legacyIndex('share-new.txt');
		RecordingTriggers::forget();

		foreach ([true, true, false] as $step => $enabled) {
			$shareBefore = $this->share('share-new.txt');
			$this->legacySetShared($legacyId, $enabled);
			$legacyCalls = RecordingTriggers::forget();
			$file = $this->files->find($newId);
			self::assertNotNull($file);
			$saved = $this->index->setShared($file, $enabled);
			$newCalls = RecordingTriggers::forget();

			self::assertSame(['ECMFILES_MODIFY'], array_column($newCalls, 'action'), 'step '.$step);
			$this->assertSameRows(['share-legacy.txt'], ['share-new.txt']);
			$this->assertSameCalls($legacyCalls, $newCalls, ['share-legacy.txt'], ['share-new.txt']);
			self::assertSame($this->share('share-new.txt'), $saved->share);
			if (!$enabled) {
				self::assertNull($saved->share, 'a disabled share is stored NULL');
				self::assertNull($this->share('share-legacy.txt'));
			} elseif ($step === 1) {
				self::assertSame($shareBefore, $this->share('share-new.txt'), 'an existing key is kept');
			} else {
				self::assertSame(32, strlen((string) $this->share('share-new.txt')));
			}
		}
	}

	public function testFailingTriggerRollsBackTheRow(): void
	{
		$this->createTwins('failing.txt', 'failing-delete.txt');
		RecordingTriggers::failOn('ECMFILES_CREATE', 'The trigger refuses');

		try {
			$this->index->add($this->directory, 'failing.txt', 'Original.txt', null, false);
			self::fail('FileTriggerException expected');
		} catch (FileTriggerException $e) {
			self::assertSame(['The trigger refuses'], $e->errors);
		}
		self::assertSame(0, $this->countRows('failing.txt'));

		RecordingTriggers::reset();
		$this->legacyIndex('failing-delete.txt');
		RecordingTriggers::failOn('ECMFILES_DELETE', 'No deletion');
		try {
			$this->index->removeFile($this->directory.'/failing-delete.txt', $this->entity);
			self::fail('FileTriggerException expected');
		} catch (FileTriggerException $e) {
			self::assertSame(['No deletion'], $e->errors);
		}
		self::assertSame(1, $this->countRows('failing-delete.txt'));
	}

	private function legacyUser(): \User
	{
		$user = $GLOBALS['user'];
		self::assertInstanceOf(\User::class, $user);

		return $user;
	}

	private function createTwins(string $legacyName, string $newName): void
	{
		file_put_contents($this->directory.'/'.$legacyName, self::CONTENT);
		file_put_contents($this->directory.'/'.$newName, self::CONTENT);
	}

	/**
	 * Indexes a file with the legacy function (no trigger kept) and returns the id of its row.
	 */
	private function legacyIndex(string $name): int
	{
		addFileIntoDatabaseIndex($this->directory, $name, 'Original.txt', 'uploaded', 0);
		RecordingTriggers::forget();
		$identifier = $this->connection->fetchValue(
			'SELECT MAX(rowid) FROM @ecm_files WHERE filepath = :filepath AND filename = :filename',
			['filepath' => $this->relativeDirectory, 'filename' => $name]
		);
		self::assertNotNull($identifier);

		return (int) $identifier;
	}

	private function legacySetShared(int $id, bool $enabled): void
	{
		$ecmFile = new \EcmFiles($this->database());
		self::assertGreaterThan(0, $ecmFile->fetch($id));
		if ($enabled) {
			if (empty($ecmFile->share)) {
				$ecmFile->share = getRandomPassword(true);
			}
		} else {
			$ecmFile->share = '';
		}
		self::assertGreaterThan(0, $ecmFile->update($this->legacyUser()));
	}

	private function countRows(string $filename): int
	{
		return (int) $this->connection->fetchValue(
			'SELECT COUNT(*) FROM @ecm_files WHERE filepath = :filepath AND filename = :filename',
			['filepath' => $this->relativeDirectory, 'filename' => $filename]
		);
	}

	private function position(string $filename): int
	{
		return (int) $this->connection->fetchValue(
			'SELECT position FROM @ecm_files WHERE filepath = :filepath AND filename = :filename',
			['filepath' => $this->relativeDirectory, 'filename' => $filename]
		);
	}

	private function share(string $filename): ?string
	{
		$share = $this->connection->fetchValue(
			'SELECT share FROM @ecm_files WHERE filepath = :filepath AND filename = :filename',
			['filepath' => $this->relativeDirectory, 'filename' => $filename]
		);

		return $share === null ? null : (string) $share;
	}

	/**
	 * @param list<string> $legacyNames Names of the legacy half: the first is the indexed file, the others the files
	 *                                  its row mentions
	 * @param list<string> $newNames    Their twins, in the same order
	 */
	private function assertSameRows(array $legacyNames, array $newNames): void
	{
		$legacyRow = $this->comparableRow($legacyNames[0], $legacyNames);
		$newRow = $this->comparableRow($newNames[0], $newNames);
		self::assertNotNull($legacyRow, 'no row for '.$legacyNames[0]);
		self::assertNotNull($newRow, 'no row for '.$newNames[0]);
		self::assertSame($legacyRow, $newRow);
	}

	/**
	 * The row without rowid, ref, filename, date_c, tms and position, the share key reduced to its presence, and
	 * the names of the twin replaced by their rank.
	 *
	 * @param list<string> $names
	 * @return array<string, ?string>|null
	 */
	private function comparableRow(string $filename, array $names): ?array
	{
		$row = $this->connection->fetchOne(
			'SELECT * FROM @ecm_files WHERE filepath = :filepath AND filename = :filename',
			['filepath' => $this->relativeDirectory, 'filename' => $filename]
		);
		if ($row === null) {
			return null;
		}
		foreach (['rowid', 'ref', 'filename', 'date_c', 'tms', 'position'] as $volatile) {
			unset($row[$volatile]);
		}
		$comparable = [];
		foreach ($row as $column => $value) {
			$comparable[(string) $column] = $value === null ? null : $this->anonymize((string) $value, $names);
		}
		$comparable['share'] = $this->shareState($row['share'] ?? null);

		return $comparable;
	}

	/**
	 * @param list<array<string, mixed>> $legacyCalls
	 * @param list<array<string, mixed>> $newCalls
	 * @param list<string>               $legacyNames
	 * @param list<string>               $newNames
	 */
	private function assertSameCalls(array $legacyCalls, array $newCalls, array $legacyNames, array $newNames): void
	{
		self::assertNotSame([], $newCalls);
		self::assertSame(
			$this->comparableCalls($legacyCalls, $legacyNames),
			$this->comparableCalls($newCalls, $newNames)
		);
	}

	/**
	 * @param list<array<string, mixed>> $calls
	 * @param list<string>               $names
	 * @return list<array<string, mixed>>
	 */
	private function comparableCalls(array $calls, array $names): array
	{
		$comparable = [];
		foreach ($calls as $call) {
			$object = $call['object'];
			self::assertIsArray($object);
			// The legacy trigger gets the object it just wrote (fk_user_c and fk_user_m not set), the new one the row read back
			unset($object['id'], $object['fk_user_c'], $object['fk_user_m']);
			$call['object'] = [];
			foreach ($object as $property => $value) {
				$call['object'][$property] = $value === null ? null : $this->anonymize((string) $value, $names);
			}
			$call['object']['share'] = $this->shareState($object['share'] ?? null);
			$comparable[] = $call;
		}

		return $comparable;
	}

	private function shareState(mixed $share): string
	{
		if ($share === null || $share === '') {
			return 'none';
		}

		return strlen((string) $share) === 32 ? 'key' : 'other';
	}

	/**
	 * @param list<string> $names
	 */
	private function anonymize(string $value, array $names): string
	{
		foreach ($names as $rank => $name) {
			$value = str_replace($name, '{name'.$rank.'}', $value);
		}

		return str_replace($this->relativeDirectory, '{directory}', $value);
	}
}
