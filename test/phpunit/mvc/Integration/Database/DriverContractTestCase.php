<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Database;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Tests\Integration\Database\Support\DriverTestTable;
use PHPUnit\Framework\TestCase;

/**
 * What every Driver guarantees, run through Connection on a scratch table. A subclass gives the Connection (its
 * DoliDB and Driver); the DDL follows Connection::dialect(), so the same contract runs on MariaDB and PostgreSQL.
 *
 * Read values are those of the engine (strings, or native int/float for mysqli prepared statements): the
 * assertions cast them, as the ORM casts on hydration.
 */
abstract class DriverContractTestCase extends TestCase
{
	private Connection $connection;

	abstract protected function connection(): Connection;

	protected function setUp(): void
	{
		$this->connection = $this->connection();
		DriverTestTable::create($this->connection);
	}

	protected function tearDown(): void
	{
		unset($GLOBALS['dolibarr_main_db_readonly']);
		DriverTestTable::drop($this->connection);
	}

	public function testInsertSelectRoundTrip(): void
	{
		$id = $this->insert('round trip', 12.5, new \DateTimeImmutable('2026-10-22 14:05:09'), true);

		$rows = $this->connection->fetchAll('SELECT rowid, label, amount, created, flag FROM @mvc_driver_test WHERE rowid = :id', ['id' => $id]);
		self::assertCount(1, $rows);
		self::assertSame(['rowid', 'label', 'amount', 'created', 'flag'], array_keys($rows[0]));
		self::assertSame($id, (int) $rows[0]['rowid']);
		self::assertSame('round trip', $rows[0]['label']);
		self::assertSame(12.5, (float) $rows[0]['amount']);
		self::assertSame('2026-10-22 14:05:09', $rows[0]['created']);
		self::assertSame(1, (int) $rows[0]['flag']);

		$row = $this->connection->fetchOne('SELECT label FROM @mvc_driver_test WHERE rowid IN (:ids)', ['ids' => [$id, $id + 1000]]);
		self::assertSame(['label' => 'round trip'], $row);
		self::assertNull($this->connection->fetchOne('SELECT label FROM @mvc_driver_test WHERE rowid IN (:ids)', ['ids' => []]));
		self::assertSame('round trip', $this->connection->fetchValue('SELECT label FROM @mvc_driver_test WHERE flag = :flag', ['flag' => true]));
		self::assertNull($this->connection->fetchValue('SELECT label FROM @mvc_driver_test WHERE flag = :flag', ['flag' => false]));
	}

	public function testNullAndUnicode(): void
	{
		$label = "Zoë – 漢字 ‘quoted’ \"double\" back\\slash";
		$id = $this->insert($label, null, null, null);

		$row = $this->connection->fetchOne('SELECT label, amount, created, flag FROM @mvc_driver_test WHERE rowid = :id', ['id' => $id]);
		self::assertSame(['label' => $label, 'amount' => null, 'created' => null, 'flag' => null], $row);
		self::assertSame($id, (int) $this->connection->fetchValue('SELECT rowid FROM @mvc_driver_test WHERE label = :label AND amount IS NULL', ['label' => $label]));
	}

	public function testInjectionAttemptIsData(): void
	{
		$hostile = "a' OR '1'='1";
		$this->insert('other', 1.0, null, false);
		$id = $this->insert($hostile, 2.0, null, false);

		$rows = $this->connection->fetchAll('SELECT rowid, label FROM @mvc_driver_test WHERE label = :label', ['label' => $hostile]);
		self::assertCount(1, $rows);
		self::assertSame($id, (int) $rows[0]['rowid']);
		self::assertSame($hostile, $rows[0]['label']);
		self::assertSame([], $this->connection->fetchAll('SELECT rowid FROM @mvc_driver_test WHERE label = :label', ['label' => "x' OR 1=1 --"]));
	}

	public function testExecuteReturnsAffectedRows(): void
	{
		$this->insert('first', 1.0, null, true);
		$this->insert('second', 2.0, null, true);
		$this->insert('third', 3.0, null, false);

		self::assertSame(2, $this->connection->execute('UPDATE @mvc_driver_test SET label = :label WHERE flag = :flag', ['label' => 'flagged', 'flag' => true]));
		self::assertSame(0, $this->connection->execute('UPDATE @mvc_driver_test SET label = :label WHERE rowid IN (:ids)', ['label' => 'none', 'ids' => []]));
		self::assertSame(1, $this->connection->execute('DELETE FROM @mvc_driver_test WHERE label = :label', ['label' => 'third']));
		self::assertSame(2, DriverTestTable::count($this->connection));
	}

	public function testLastInsertId(): void
	{
		$first = $this->insert('first', null, null, null);
		$second = $this->insert('second', null, null, null);

		self::assertGreaterThan(0, $first);
		self::assertGreaterThan($first, $second);
		self::assertSame('second', $this->connection->fetchValue('SELECT label FROM @mvc_driver_test WHERE rowid = :id', ['id' => $second]));
	}

	public function testLastInsertIdRefusesAPrefixedTable(): void
	{
		$this->insert('first', null, null, null);

		try {
			$this->connection->lastInsertId('llx_'.DriverTestTable::NAME);
			self::fail('A prefixed table name must throw');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('llx_mvc_driver_test', $e->getMessage());
		}
	}

	public function testNegativeNumberAfterMinusIsNotAComment(): void
	{
		self::assertSame(15, (int) $this->connection->fetchValue('SELECT 10-:number', ['number' => -5]));
		self::assertSame(12.5, (float) $this->connection->fetchValue('SELECT 10-:number', ['number' => -2.5]));
		// -0.0 is not < 0 but prints '-0.0': '10--0.0+1' would end in a comment on PostgreSQL
		self::assertSame(11.0, (float) $this->connection->fetchValue('SELECT 10-:number+1', ['number' => -0.0]));

		$target = $this->insert('target', 10.0, null, null);
		$other = $this->insert('other', 10.0, null, null);

		self::assertSame(1, $this->connection->execute('UPDATE @mvc_driver_test SET amount = amount-:delta WHERE rowid = :id', ['delta' => -5, 'id' => $target]));
		self::assertSame(15.0, (float) $this->connection->fetchValue('SELECT amount FROM @mvc_driver_test WHERE rowid = :id', ['id' => $target]));
		self::assertSame(10.0, (float) $this->connection->fetchValue('SELECT amount FROM @mvc_driver_test WHERE rowid = :id', ['id' => $other]));
	}

	public function testErrorHidesBoundValues(): void
	{
		$secret = 'secret-'.bin2hex(random_bytes(6));
		$statements = [
			'SELECT FROM WHERE label = :secret',
			'INSERT INTO @mvc_driver_test (no_such_column) VALUES (:secret)',
		];
		foreach ($statements as $sql) {
			try {
				$this->connection->fetchAll($sql, ['secret' => $secret]);
				self::fail('Invalid SQL must throw: '.$sql);
			} catch (DatabaseException $e) {
				self::assertStringNotContainsString($secret, $e->getMessage());
				self::assertStringContainsString('(SQL: ', $e->getMessage());
				self::assertMatchesRegularExpression('/\?|\$1/', $e->getMessage(), 'The SQL shows placeholders');
				self::assertNotSame('', $e->engineCode());
			}
		}
	}

	public function testReadOnlyModeRefusesWrites(): void
	{
		$this->insert('before', null, null, null);
		$GLOBALS['dolibarr_main_db_readonly'] = 1;

		foreach (['INSERT INTO @mvc_driver_test (label) VALUES (:label)', ' update @mvc_driver_test SET label = :label', 'DELETE FROM @mvc_driver_test WHERE label <> :label'] as $sql) {
			try {
				$this->connection->execute($sql, ['label' => 'refused']);
				self::fail('Read-only mode must refuse: '.$sql);
			} catch (DatabaseException $e) {
				self::assertSame('APPREADONLY', $e->engineCode());
				self::assertSame('APPREADONLY', $this->connection->legacy()->lasterrno());
			}
		}
		self::assertSame(1, DriverTestTable::count($this->connection));

		unset($GLOBALS['dolibarr_main_db_readonly']);
		self::assertSame('before', $this->connection->fetchValue('SELECT label FROM @mvc_driver_test WHERE label <> :label', ['label' => 'refused']));
	}

	public function testLastQueryIsVisibleToLegacy(): void
	{
		$db = $this->connection->legacy();
		$this->connection->fetchAll('SELECT rowid FROM @mvc_driver_test WHERE label = :label', ['label' => 'visible']);
		self::assertStringContainsString('SELECT rowid FROM llx_mvc_driver_test WHERE label = ', $db->lastquery);

		try {
			$this->connection->fetchAll('SELECT no_such_column FROM @mvc_driver_test WHERE label = :label', ['label' => 'visible']);
			self::fail('Unknown column must throw');
		} catch (DatabaseException $e) {
			self::assertStringContainsString('SELECT no_such_column FROM llx_mvc_driver_test', $db->lastqueryerror());
			self::assertNotSame('', (string) $db->lasterror());
			self::assertNotSame('', (string) $db->lasterrno());
			self::assertStringContainsString((string) $db->lasterror(), $e->getMessage());
		}
	}

	private function insert(?string $label, ?float $amount, ?\DateTimeImmutable $created, ?bool $flag): int
	{
		$affected = $this->connection->execute(
			'INSERT INTO @mvc_driver_test (label, amount, created, flag) VALUES (:label, :amount, :created, :flag)',
			['label' => $label, 'amount' => $amount, 'created' => $created, 'flag' => $flag]
		);
		self::assertSame(1, $affected);

		return $this->connection->lastInsertId(DriverTestTable::NAME);
	}
}
