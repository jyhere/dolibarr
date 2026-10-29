<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Files;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\MysqliDriver;

/**
 * The file repositories on MariaDB.
 */
final class MysqlFileRepositoriesTest extends FileRepositoriesTestCase
{
	protected function connection(): Connection
	{
		$db = $GLOBALS['db'];
		self::assertInstanceOf(\DoliDB::class, $db);
		$connection = Connection::forLegacy($db);
		self::assertInstanceOf(MysqliDriver::class, $connection->driver());

		return $connection;
	}
}
