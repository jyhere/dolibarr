<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\Documents;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\MysqliDriver;

/**
 * The attached files actions parity on MariaDB.
 */
final class MysqlAttachedFilesParityTest extends AttachedFilesParityTestCase
{
	protected function database(): \DoliDB
	{
		$database = $GLOBALS['db'];
		self::assertInstanceOf(\DoliDB::class, $database);
		self::assertInstanceOf(MysqliDriver::class, Connection::forLegacy($database)->driver());

		return $database;
	}
}
