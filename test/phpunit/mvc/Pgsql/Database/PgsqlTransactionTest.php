<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Database;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Tests\Integration\Database\Support\DriverTestTable;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;
use PHPUnit\Framework\TestCase;

final class PgsqlTransactionTest extends TestCase
{
	private Connection $connection;

	protected function setUp(): void
	{
		$db = PgsqlDatabase::doliDb();
		$this->connection = new Connection($db, new PgsqlDriver($db));
		DriverTestTable::create($this->connection);
	}

	protected function tearDown(): void
	{
		while ((int) $this->connection->legacy()->transaction_opened > 0) {
			$this->connection->legacy()->rollback();
		}
		DriverTestTable::drop($this->connection);
	}

	public function testOrmWriteRolledBackWithLegacyTransaction(): void
	{
		$db = $this->connection->legacy();
		self::assertSame(1, $db->begin());

		$this->connection->transactional(fn (): int => $this->connection->execute('INSERT INTO @mvc_driver_test (label) VALUES (:label)', ['label' => 'orm']));
		self::assertNotFalse($db->query("INSERT INTO ".MAIN_DB_PREFIX."mvc_driver_test (label) VALUES ('legacy')"));
		self::assertSame(2, DriverTestTable::count($this->connection), 'Both writes are visible inside the transaction');
		self::assertSame(1, (int) $db->transaction_opened, 'transactional() only raised and lowered the nesting level');

		$db->rollback();

		self::assertSame(0, (int) $db->transaction_opened);
		self::assertSame(0, DriverTestTable::count($this->connection));
	}
}
