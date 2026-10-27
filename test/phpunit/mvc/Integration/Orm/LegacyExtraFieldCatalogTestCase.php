<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Legacy\LegacyExtraFieldCatalog;
use Dolibarr\Core\Logger;
use Dolibarr\Tests\Integration\Orm\Fixture\RecordingDriver;
use PHPUnit\Framework\TestCase;

/**
 * LegacyExtraFieldCatalog on the real llx_extrafields (rows inserted in a transaction rolled back by tearDown):
 * the filter of ExtraFields::fetch_name_optionals_label() (entity 0 or the current one, by pos), no 'separate',
 * no name that cannot be an unquoted column or is a technical or secret column, one statement per element type.
 * A subclass gives the connection: MariaDB (Integration) and PostgreSQL (Pgsql).
 */
abstract class LegacyExtraFieldCatalogTestCase extends TestCase
{
	private const ELEMENT = 'mvc_orm_catalog';

	private Connection $connection;

	abstract protected function connection(): Connection;

	protected function setUp(): void
	{
		$this->connection = $this->connection();
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
	}

	public function testNamesOfTheCurrentEntityByPosition(): void
	{
		self::assertSame(1, (int) $GLOBALS['conf']->entity, 'The test install runs entity 1');
		$this->define('zeta_shared', entity: 0, position: 30);
		$this->define('alpha_mine', entity: 1, position: 10);
		$this->define('other_company', entity: 2, position: 20);
		$this->define('a_separator', entity: 1, position: 40, type: 'separate');
		$this->define('bad-name', entity: 1, position: 50);
		$this->define('1starts_with_digit', entity: 1, position: 51);
		$this->define('import_key', entity: 1, position: 52);
		$this->define('fk_object', entity: 0, position: 53);
		$this->define('Pass', entity: 1, position: 54);
		$this->define('MixedCase', entity: 1, position: 60);
		$this->define('another_element', entity: 1, position: 5, element: self::ELEMENT.'_other');

		$catalog = new LegacyExtraFieldCatalog($this->connection, new Logger());

		self::assertSame(['alpha_mine', 'zeta_shared', 'MixedCase'], $catalog->namesFor(self::ELEMENT));
		self::assertSame(['another_element'], $catalog->namesFor(self::ELEMENT.'_other'));
		self::assertSame([], $catalog->namesFor(self::ELEMENT.'_none'));
	}

	public function testOneStatementPerElementType(): void
	{
		$this->define('color', entity: 1, position: 1);
		$recorder = new RecordingDriver($this->connection->driver());
		$catalog = new LegacyExtraFieldCatalog(new Connection($this->connection->legacy(), $recorder), new Logger());

		self::assertSame(['color'], $catalog->namesFor(self::ELEMENT));
		self::assertSame(['color'], $catalog->namesFor(self::ELEMENT));
		self::assertSame([], $catalog->namesFor(self::ELEMENT.'_none'));
		self::assertSame([], $catalog->namesFor(self::ELEMENT.'_none'));

		self::assertCount(2, $recorder->statements, 'Cached per element type, an empty catalog included');
		self::assertStringStartsWith('SELECT name, type, entity FROM '.MAIN_DB_PREFIX.'extrafields WHERE elementtype = ', $recorder->statements[0]);
	}

	private function define(string $name, int $entity, int $position, string $type = 'varchar', string $element = self::ELEMENT): void
	{
		$this->connection->execute(
			'INSERT INTO @extrafields (name, entity, elementtype, label, type, pos) VALUES (:name, :entity, :element, :label, :type, :position)',
			['name' => $name, 'entity' => $entity, 'element' => $element, 'label' => $name, 'type' => $type, 'position' => $position]
		);
	}
}
