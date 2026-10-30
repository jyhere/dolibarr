<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Files;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\MysqliDriver;

/**
 * The file index parity on MariaDB.
 */
final class MysqlFileIndexParityTest extends FileIndexParityTestCase
{
	protected function database(): \DoliDB
	{
		$database = $GLOBALS['db'];
		self::assertInstanceOf(\DoliDB::class, $database);
		self::assertInstanceOf(MysqliDriver::class, Connection::forLegacy($database)->driver());

		return $database;
	}
}
