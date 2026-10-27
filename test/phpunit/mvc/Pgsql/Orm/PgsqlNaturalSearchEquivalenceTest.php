<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Orm;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Tests\Integration\Orm\NaturalSearchEquivalenceTestCase;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;

/**
 * NaturalSearch against natural_search() on PostgreSQL: the legacy SQL built with the PostgreSQL DoliDB as global
 * $db and converted by it (LIKE → ILIKE), the bound one through pg_query_params.
 */
final class PgsqlNaturalSearchEquivalenceTest extends NaturalSearchEquivalenceTestCase
{
	protected function connection(): Connection
	{
		$connection = Connection::forLegacy(PgsqlDatabase::doliDb());
		self::assertInstanceOf(PgsqlDriver::class, $connection->driver());

		return $connection;
	}
}
