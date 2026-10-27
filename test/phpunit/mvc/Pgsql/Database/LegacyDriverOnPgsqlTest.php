<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Database;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\LegacyDriver;
use Dolibarr\Tests\Integration\Database\DriverContractTestCase;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;

/**
 * The fallback driver on the PostgreSQL DoliDB (what Connection::forLegacy() uses for pgsql until PgsqlDriver).
 */
final class LegacyDriverOnPgsqlTest extends DriverContractTestCase
{
	protected function connection(): Connection
	{
		$db = PgsqlDatabase::doliDb();

		return new Connection($db, new LegacyDriver($db));
	}

	public function testForLegacyGivesThePgsqlDialect(): void
	{
		self::assertSame('pgsql', Connection::forLegacy(PgsqlDatabase::doliDb())->dialect());
	}
}
