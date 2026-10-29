<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Module\Documents;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Tests\Integration\Module\Documents\AttachedFilesParityTestCase;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;

/**
 * The attached files actions parity on PostgreSQL: the legacy globals point to the PostgreSQL connection for the
 * legacy half.
 */
final class PgsqlAttachedFilesParityTest extends AttachedFilesParityTestCase
{
	protected function database(): \DoliDB
	{
		$database = PgsqlDatabase::doliDb();
		self::assertInstanceOf(PgsqlDriver::class, Connection::forLegacy($database)->driver());

		return $database;
	}
}
