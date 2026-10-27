<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Orm;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Tests\Integration\Orm\LegacyExtraFieldCatalogTestCase;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;

/**
 * The extra fields catalog on PostgreSQL (pg_query_params).
 */
final class PgsqlLegacyExtraFieldCatalogTest extends LegacyExtraFieldCatalogTestCase
{
	protected function connection(): Connection
	{
		$connection = Connection::forLegacy(PgsqlDatabase::doliDb());
		self::assertInstanceOf(PgsqlDriver::class, $connection->driver());

		return $connection;
	}
}
