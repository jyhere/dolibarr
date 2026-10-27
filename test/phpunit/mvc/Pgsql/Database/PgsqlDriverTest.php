<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Database;

use Dolibarr\Core\Database\BoundStatement;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Tests\Integration\Database\DriverContractTestCase;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;

/**
 * The prepared statements driver (pg_query_params) on the PostgreSQL DoliDB, under the shared driver contract.
 */
final class PgsqlDriverTest extends DriverContractTestCase
{
	protected function connection(): Connection
	{
		return new Connection(PgsqlDatabase::doliDb(), new PgsqlDriver(PgsqlDatabase::doliDb()));
	}

	public function testForLegacyChoosesThePgsqlDriver(): void
	{
		self::assertInstanceOf(PgsqlDriver::class, Connection::forLegacy(PgsqlDatabase::doliDb())->driver());
	}

	public function testPlaceholdersAreNumberedAndTypedOutsideLiterals(): void
	{
		$driver = new PgsqlDriver(PgsqlDatabase::doliDb());

		$statement = $driver->placeholders(new BoundStatement("SELECT '?', \"a?b\", 'it''s ?', 'back\\' FROM t WHERE x = ? AND y IN (?, ?) AND z = ? AND w = ? AND v = ? ", [1, 2.5, 'text', null, 3000000000, -2147483648]));

		self::assertSame("SELECT '?', \"a?b\", 'it''s ?', 'back\\' FROM t WHERE x = \$1::integer AND y IN (\$2::numeric, \$3) AND z = \$4 AND w = \$5::bigint AND v = \$6::integer ", $statement->sql);
	}

	public function testPlaceholderCountMustMatchValues(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new PgsqlDriver(PgsqlDatabase::doliDb()))->placeholders(new BoundStatement('SELECT ? , ?', [1]));
	}

	public function testBackslashIsAnOrdinaryCharacterInAPgsqlLiteral(): void
	{
		$connection = $this->connection();
		self::assertSame('back\\', $connection->fetchValue("SELECT 'back\\' || CAST(:suffix AS text)", ['suffix' => '']));
		self::assertSame("a'b", $connection->fetchValue("SELECT 'a''b' || CAST(:suffix AS text)", ['suffix' => '']));
	}

	public function testQuestionMarkInsideAStringIsDataNotAPlaceholder(): void
	{
		self::assertSame('what?', $this->connection()->fetchValue("SELECT 'what?' || CAST(:suffix AS text)", ['suffix' => '']));
	}

	public function testNumbersAreTypedLikeLiterals(): void
	{
		$connection = $this->connection();
		$id = $connection->execute('INSERT INTO @mvc_driver_test (label, flag) VALUES (:label, :flag)', ['label' => 'typed', 'flag' => 1]);
		self::assertSame(1, $id, 'An int is stored in a smallint column');
		$rowid = $connection->lastInsertId('mvc_driver_test');
		$connection->execute('INSERT INTO @mvc_driver_test (label) VALUES (:label)', ['label' => 'second']);

		self::assertSame('typed', $connection->fetchValue('SELECT label FROM @mvc_driver_test WHERE rowid = :id', ['id' => $rowid]));
		self::assertSame(1.5 * ($rowid + $rowid + 1), (float) $connection->fetchValue('SELECT SUM(rowid) * :factor FROM @mvc_driver_test', ['factor' => 1.5]));
		self::assertSame($rowid + 1.5, (float) $connection->fetchValue('SELECT COALESCE(MAX(rowid), 0) + :step FROM @mvc_driver_test WHERE rowid = :id', ['step' => 1.5, 'id' => $rowid]));
		self::assertSame(['label' => 'second'], $connection->fetchOne('SELECT label FROM @mvc_driver_test ORDER BY rowid LIMIT :limit OFFSET :offset', ['limit' => 1, 'offset' => 1]));
		self::assertSame(3000000000, (int) $connection->fetchValue('SELECT :big', ['big' => 3000000000]));
	}

	public function testLastInsertIdFailureThrowsAndIsBookkept(): void
	{
		$connection = $this->connection();
		try {
			$connection->lastInsertId('mvc_driver_test');
			self::fail('currval() without insert in this session must throw');
		} catch (DatabaseException $e) {
			self::assertNotSame('', $e->engineCode());
			self::assertNotSame('', (string) $connection->legacy()->lasterror());
		}
		self::assertSame(1, (int) $connection->fetchValue('SELECT 1'), 'The link is still usable outside a transaction');
	}
}
