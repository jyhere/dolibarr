<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Database;

use Dolibarr\Core\Database\BoundStatement;
use Dolibarr\Core\Database\Connection;
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

	public function testPlaceholdersAreNumberedOutsideLiterals(): void
	{
		$driver = new PgsqlDriver(PgsqlDatabase::doliDb());

		$statement = $driver->placeholders(new BoundStatement("SELECT '?', \"a?b\", 'it''s ?' FROM t WHERE x = ? AND y IN (?, ?) AND z = 'q\\'?' AND w = ?", [1, 2, 3, 4]));

		self::assertSame("SELECT '?', \"a?b\", 'it''s ?' FROM t WHERE x = \$1 AND y IN (\$2, \$3) AND z = 'q\\'?' AND w = \$4", $statement->sql);
		self::assertSame([1, 2, 3, 4], $statement->values);
	}

	public function testQuestionMarkInsideAStringIsDataNotAPlaceholder(): void
	{
		$connection = $this->connection();
		self::assertSame('what?', $connection->fetchValue("SELECT 'what?' || CAST(:suffix AS text)", ['suffix' => '']));
	}
}
