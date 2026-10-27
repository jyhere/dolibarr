<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Database;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Tests\Integration\Database\Support\DriverTestTable;
use PHPUnit\Framework\TestCase;

final class ConnectionTransactionTest extends TestCase
{
	private Connection $connection;

	protected function setUp(): void
	{
		$this->connection = Connection::forLegacy($GLOBALS['db']);
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

	public function testTransactionalCommitsAndReturnsTheResult(): void
	{
		$result = $this->connection->transactional(function (): string {
			$this->connection->execute('INSERT INTO @mvc_driver_test (label) VALUES (:label)', ['label' => 'kept']);

			return 'done';
		});

		self::assertSame('done', $result);
		self::assertSame(0, (int) $this->connection->legacy()->transaction_opened);
		self::assertSame(1, DriverTestTable::count($this->connection));
	}

	public function testTransactionalRollsBackAndRethrows(): void
	{
		$failure = new \RuntimeException('work failed');
		try {
			$this->connection->transactional(function () use ($failure): void {
				$this->connection->execute('INSERT INTO @mvc_driver_test (label) VALUES (:label)', ['label' => 'dropped']);

				throw $failure;
			});
			self::fail('The exception of the work must be rethrown');
		} catch (\RuntimeException $e) {
			self::assertSame($failure, $e);
		}

		self::assertSame(0, (int) $this->connection->legacy()->transaction_opened);
		self::assertSame(0, DriverTestTable::count($this->connection));
	}
}
