<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\MysqliDriver;

/**
 * The relations loaded by with() on MariaDB (mysqli prepared statements).
 */
final class MysqlRelationTest extends RelationTestCase
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
