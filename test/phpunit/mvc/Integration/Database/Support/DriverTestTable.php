<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Database\Support;

use Dolibarr\Core\Database\Connection;

/**
 * The scratch table of the driver tests (llx_mvc_driver_test), created and dropped by each test, with the DDL of
 * the dialect of the connection. On PostgreSQL the double column is numeric, as the Dolibarr pgsql install
 * converts MySQL double columns (pgsql.class.php::convertSQLFromMysql()).
 */
final class DriverTestTable
{
	public const NAME = 'mvc_driver_test';

	public static function create(Connection $connection): void
	{
		self::drop($connection);
		if ($connection->dialect() === 'pgsql') {
			$connection->execute('CREATE TABLE @'.self::NAME.' (rowid SERIAL PRIMARY KEY, label varchar(64), amount numeric, created timestamp, flag smallint)');

			return;
		}
		$connection->execute('CREATE TABLE @'.self::NAME.' (rowid integer AUTO_INCREMENT PRIMARY KEY, label varchar(64), amount double, created datetime, flag smallint) ENGINE=InnoDB');
	}

	public static function drop(Connection $connection): void
	{
		$connection->execute('DROP TABLE IF EXISTS @'.self::NAME);
	}

	public static function count(Connection $connection): int
	{
		return (int) $connection->fetchValue('SELECT COUNT(*) FROM @'.self::NAME);
	}
}
