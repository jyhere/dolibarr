<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Files;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Tests\Integration\Files\FileIndexParityTestCase;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;

/**
 * The file index parity on PostgreSQL: the legacy globals point to the PostgreSQL connection for the legacy half.
 */
final class PgsqlFileIndexParityTest extends FileIndexParityTestCase
{
	protected function database(): \DoliDB
	{
		$database = PgsqlDatabase::doliDb();
		self::assertInstanceOf(PgsqlDriver::class, Connection::forLegacy($database)->driver());

		return $database;
	}
}
