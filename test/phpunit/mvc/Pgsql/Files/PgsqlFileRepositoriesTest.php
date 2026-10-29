<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Files;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Tests\Integration\Files\FileRepositoriesTestCase;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;

/**
 * The file repositories on PostgreSQL.
 */
final class PgsqlFileRepositoriesTest extends FileRepositoriesTestCase
{
	protected function connection(): Connection
	{
		$connection = Connection::forLegacy(PgsqlDatabase::doliDb());
		self::assertInstanceOf(PgsqlDriver::class, $connection->driver());

		return $connection;
	}
}
