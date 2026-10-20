<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
	public function testFetchOneAndEscape(): void
	{
		$c = new Connection($GLOBALS['db']);
		$row = $c->fetchOne("SELECT login FROM ".$c->prefix()."user WHERE rowid = 1");
		self::assertNotNull($row);
		self::assertSame($GLOBALS['user']->login, $row->login);
		self::assertSame("O\\'Neil", $c->escape("O'Neil"));
	}

	public function testInvalidSqlThrowsDatabaseException(): void
	{
		$this->expectException(DatabaseException::class);
		(new Connection($GLOBALS['db']))->fetchAll('SELECT * FROM nope_table');
	}
}
