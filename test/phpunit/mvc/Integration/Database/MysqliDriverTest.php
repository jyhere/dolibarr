<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Database;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\MysqliDriver;

final class MysqliDriverTest extends DriverContractTestCase
{
	protected function connection(): Connection
	{
		$db = $GLOBALS['db'];
		self::assertSame('mysqli', $db->type);

		return new Connection($db, new MysqliDriver($db));
	}

	public function testForLegacyPicksTheMysqliDriver(): void
	{
		$connection = Connection::forLegacy($GLOBALS['db']);

		self::assertInstanceOf(MysqliDriver::class, $connection->driver());
		self::assertSame('mysql', $connection->dialect());
	}
}
