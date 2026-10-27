<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql;

use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;
use PHPUnit\Framework\TestCase;

final class PgsqlInstallTest extends TestCase
{
	public function testDolibarrSchemaIsInstalled(): void
	{
		$database = PgsqlDatabase::doliDb();
		self::assertSame('pgsql', $database->type);

		$constants = $database->query('SELECT COUNT(*) AS nb FROM llx_const');
		self::assertNotFalse($constants);
		self::assertGreaterThan(0, (int) $database->fetch_object($constants)->nb);

		$adminLogin = (string) getenv('DOLIBARR_ADMIN_LOGIN');
		self::assertNotSame('', $adminLogin);
		$users = $database->query("SELECT COUNT(*) AS nb FROM llx_user WHERE login = '".$database->escape($adminLogin)."'");
		self::assertNotFalse($users);
		self::assertSame(1, (int) $database->fetch_object($users)->nb);
	}
}
