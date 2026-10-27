<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Orm;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Tests\Integration\Orm\RelationTestCase;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;

/**
 * The relations loaded by with() on PostgreSQL (pg_query_params), PgsqlGrammar chosen from the connection.
 */
final class PgsqlRelationTest extends RelationTestCase
{
	protected function connection(): Connection
	{
		$connection = Connection::forLegacy(PgsqlDatabase::doliDb());
		self::assertInstanceOf(PgsqlDriver::class, $connection->driver());

		return $connection;
	}
}
