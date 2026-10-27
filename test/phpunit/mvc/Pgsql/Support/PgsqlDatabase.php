<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Support;

/**
 * Second connection, reserved to the tests: the PostgreSQL install made by docker/install-pgsql.sh.
 * The website itself stays on MariaDB.
 */
final class PgsqlDatabase
{
	private const CONF_FILE = '/application/docker/php-fpm/conf.pgsql.php';

	private static ?\DoliDB $connection = null;

	public static function doliDb(): \DoliDB
	{
		if (self::$connection !== null) {
			return self::$connection;
		}

		$settings = self::readSettings();
		require_once DOL_DOCUMENT_ROOT.'/core/db/DoliDB.class.php';
		$connection = getDoliDBInstance(
			'pgsql',
			$settings['host'],
			$settings['user'],
			$settings['pass'],
			$settings['name'],
			(int) $settings['port']
		);
		if (!$connection->connected || !$connection->database_selected) {
			throw new \RuntimeException(
				'PostgreSQL is unreachable ('.$settings['host'].':'.$settings['port'].'/'.$settings['name'].'): '
				.'run "docker compose up -d pgsql && bash docker/install-pgsql.sh". '.(string) ($connection->error ?? '')
			);
		}
		self::$connection = $connection;

		return $connection;
	}

	/**
	 * Reads the conf file in an isolated scope (it defines plain variables).
	 *
	 * @return array{host: string, port: string, name: string, user: string, pass: string}
	 */
	private static function readSettings(): array
	{
		if (!is_readable(self::CONF_FILE)) {
			throw new \RuntimeException('Missing '.self::CONF_FILE.' (see test/mvc/README.md)');
		}

		return (static function (): array {
			include self::CONF_FILE;

			return [
				'host' => (string) $dolibarr_main_db_host,
				'port' => (string) $dolibarr_main_db_port,
				'name' => (string) $dolibarr_main_db_name,
				'user' => (string) $dolibarr_main_db_user,
				'pass' => (string) $dolibarr_main_db_pass,
			];
		})();
	}
}
