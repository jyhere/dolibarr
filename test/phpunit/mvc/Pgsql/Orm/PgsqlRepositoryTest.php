<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Orm;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Tests\Integration\Orm\RepositoryTestCase;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;

/**
 * The repository contract on PostgreSQL (pg_query_params), PgsqlGrammar chosen from the connection.
 */
final class PgsqlRepositoryTest extends RepositoryTestCase
{
	protected function connection(): Connection
	{
		$connection = Connection::forLegacy(PgsqlDatabase::doliDb());
		self::assertInstanceOf(PgsqlDriver::class, $connection->driver());

		return $connection;
	}
}
