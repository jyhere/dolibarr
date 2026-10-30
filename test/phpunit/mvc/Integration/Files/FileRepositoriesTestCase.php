<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Files;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Files\EcmFile;
use Dolibarr\Core\Files\EcmFileRepository;
use Dolibarr\Core\Files\ExternalLink;
use Dolibarr\Core\Files\ExternalLinkRepository;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyEntityScope;
use Dolibarr\Core\Legacy\LegacyExtraFieldCatalog;
use Dolibarr\Core\Logger;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Core\Orm\Snapshots;
use PHPUnit\Framework\TestCase;

/**
 * EcmFileRepository and ExternalLinkRepository on the real llx_ecm_files and llx_links (every test in a transaction
 * rolled back by tearDown). A subclass gives the connection: MariaDB (Integration) and PostgreSQL (Pgsql).
 */
abstract class FileRepositoriesTestCase extends TestCase
{
	private Connection $connection;

	private EcmFileRepository $files;

	private ExternalLinkRepository $links;

	abstract protected function connection(): Connection;

	protected function setUp(): void
	{
		$this->connection = $this->connection();
		$this->connection->begin();
		$hydrator = new Hydrator();
		$orm = new Orm(
			$this->connection,
			new MappingRegistry(),
			new LegacyEntityScope(),
			$hydrator,
			new Snapshots($hydrator),
			new LegacyExtraFieldCatalog($this->connection, new LegacyConfig(LegacyContext::fromGlobals()), new Logger())
		);
		$this->files = (new EcmFileRepository($orm))->withoutEntityScope();
		$this->links = (new ExternalLinkRepository($orm))->withoutEntityScope();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
	}

	public function testSavedEcmFileRoundTripsEveryMappedColumn(): void
	{
		$created = new \DateTimeImmutable('2026-10-27 10:11:12');
		$saved = $this->files->save(new EcmFile(
			null,
			'refhash',
			'contenthash',
			'sharehash',
			1,
			'user/5',
			'photo.jpg',
			'user',
			5,
			'/home/me/photo.jpg',
			'A description',
			'one,two',
			'Some content',
			'1',
			3,
			'uploaded',
			'{"a":1}',
			$created,
			null,
			7,
			8,
			'acl',
			9
		));
		self::assertIsInt($saved->id);

		$row = $this->connection->fetchOne(
			'SELECT ref, label, share, entity, filepath, filename, src_object_type, src_object_id, fullpath_orig, description, keywords, content, cover, position, gen_or_uploaded, extraparams, date_c, fk_user_c, fk_user_m, acl, agenda_id FROM @ecm_files WHERE rowid = :id',
			['id' => $saved->id]
		);

		self::assertNotNull($row);
		self::assertSame(
			['refhash', 'contenthash', 'sharehash', 1, 'user/5', 'photo.jpg', 'user', 5, '/home/me/photo.jpg', 'A description', 'one,two', 'Some content', '1', 3, 'uploaded', '{"a":1}', '2026-10-27 10:11:12', 7, 8, 'acl', 9],
			[
				$row['ref'], $row['label'], $row['share'], (int) $row['entity'], $row['filepath'], $row['filename'], $row['src_object_type'],
				(int) $row['src_object_id'], $row['fullpath_orig'], $row['description'], $row['keywords'], $row['content'], $row['cover'],
				(int) $row['position'], $row['gen_or_uploaded'], $row['extraparams'], substr((string) $row['date_c'], 0, 19),
				(int) $row['fk_user_c'], (int) $row['fk_user_m'], $row['acl'], (int) $row['agenda_id'],
			]
		);
		$reloaded = $this->files->find($saved->id);
		self::assertInstanceOf(EcmFile::class, $reloaded);
		self::assertSame('photo.jpg', $reloaded->filename);
		self::assertSame('user', $reloaded->srcObjectType);
		self::assertSame('uploaded', $reloaded->genOrUploaded);
		self::assertNotNull($reloaded->modifiedAt);
	}

	public function testFindByPathIsExactOnEntityFilepathAndFilename(): void
	{
		$this->files->save($this->ecmFile(1, 'user/5', 'a.pdf'));
		$second = $this->files->save($this->ecmFile(2, 'user/5', 'a.pdf'));

		$found = $this->files->findByPath(2, 'user/5', 'a.pdf');

		self::assertNotNull($found);
		self::assertSame($second->id, $found->id);
		self::assertNull($this->files->findByPath(2, 'user/5', 'b.pdf'));
		self::assertNull($this->files->findByPath(2, 'user/6', 'a.pdf'));
		self::assertNull($this->files->findByPath(3, 'user/5', 'a.pdf'));
	}

	public function testInDirectoryIsSortedByPositionThenFilename(): void
	{
		$this->files->save($this->ecmFile(1, 'user/5', 'c.pdf', position: 1));
		$this->files->save($this->ecmFile(1, 'user/5', 'b.pdf', position: 2));
		$this->files->save($this->ecmFile(1, 'user/5', 'a.pdf', position: 2));
		$this->files->save($this->ecmFile(2, 'user/5', 'other.pdf', position: 0));

		$names = array_map(static fn (EcmFile $file): string => $file->filename, $this->files->inDirectory(1, 'user/5'));

		self::assertSame(['c.pdf', 'a.pdf', 'b.pdf'], $names);
	}

	public function testNextPositionIgnoresEntityAndStartsAtOne(): void
	{
		$filepath = 'user/mvc-next-position';
		self::assertSame(1, $this->files->nextPosition($filepath));

		$this->files->save($this->ecmFile(1, $filepath, 'a.pdf', position: 3));
		$this->files->save($this->ecmFile(2, $filepath, 'b.pdf', position: 7));

		self::assertSame(8, $this->files->nextPosition($filepath));
	}

	public function testForObjectFiltersEntityUnlessZero(): void
	{
		$this->links->save($this->link(1, 'user', 5, 'one'));
		$this->links->save($this->link(2, 'user', 5, 'two'));
		$this->links->save($this->link(1, 'user', 6, 'elsewhere'));

		$current = $this->links->forObject('user', 5, 1);
		$all = $this->links->forObject('user', 5, 0);

		self::assertSame(['one'], array_map(static fn (ExternalLink $link): string => $link->label, $current));
		self::assertSame(['one', 'two'], array_map(static fn (ExternalLink $link): string => $link->label, $all));
	}

	public function testFindForObjectRefusesALinkOfAnotherObject(): void
	{
		$link = $this->links->save($this->link(1, 'user', 5, 'one'));
		self::assertIsInt($link->id);

		self::assertSame($link->id, $this->links->findForObject($link->id, 'user', 5, 1)?->id);
		self::assertNull($this->links->findForObject($link->id, 'user', 6, 1));
		self::assertNull($this->links->findForObject($link->id, 'thirdparty', 5, 1));
		self::assertNull($this->links->findForObject($link->id, 'user', 5, 2));
	}

	public function testDeleteRemovesTheExtraFieldsRow(): void
	{
		$file = $this->files->save($this->ecmFile(1, 'user/5', 'a.pdf'));
		self::assertIsInt($file->id);
		$this->connection->execute('INSERT INTO @ecm_files_extrafields (fk_object) VALUES (:id)', ['id' => $file->id]);
		self::assertSame(1, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @ecm_files_extrafields WHERE fk_object = :id', ['id' => $file->id]));

		$this->files->delete($file);

		self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @ecm_files_extrafields WHERE fk_object = :id', ['id' => $file->id]));
		self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @ecm_files WHERE rowid = :id', ['id' => $file->id]));
	}

	public function testDeleteOfALinkRemovesItsExtraFieldsRow(): void
	{
		$link = $this->links->save($this->link(1, 'user', 5, 'one'));
		self::assertIsInt($link->id);
		$this->connection->execute('INSERT INTO @links_extrafields (fk_object) VALUES (:id)', ['id' => $link->id]);

		$this->links->delete($link);

		self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @links_extrafields WHERE fk_object = :id', ['id' => $link->id]));
		self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @links WHERE rowid = :id', ['id' => $link->id]));
	}

	private function ecmFile(int $entity, string $filepath, string $filename, ?int $position = null): EcmFile
	{
		return new EcmFile(null, null, 'hash', null, $entity, $filepath, $filename, position: $position);
	}

	private function link(int $entity, string $objectType, int $objectId, string $label): ExternalLink
	{
		return new ExternalLink(null, $entity, new \DateTimeImmutable('2026-10-27 10:00:00'), 'https://example.org/'.$label, $label, $objectType, $objectId);
	}
}
