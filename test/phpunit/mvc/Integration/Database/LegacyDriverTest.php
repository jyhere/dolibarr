<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Database;

use Dolibarr\Core\Database\BoundStatement;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\LegacyDriver;

/**
 * The fallback driver (escaped interpolation, then DoliDB::query()), run on the MariaDB DoliDB.
 */
final class LegacyDriverTest extends DriverContractTestCase
{
	protected function connection(): Connection
	{
		return new Connection($GLOBALS['db'], new LegacyDriver($GLOBALS['db']));
	}

	public function testQuestionMarkInsideLiteralIsNotAPlaceholder(): void
	{
		$driver = new LegacyDriver($GLOBALS['db']);

		$rows = $driver->fetchAll(new BoundStatement("SELECT '?' AS mark, ? AS label, ? AS amount, ? AS nothing", ["it's", 1.5, null]));

		self::assertSame([['mark' => '?', 'label' => "it's", 'amount' => '1.5', 'nothing' => null]], $rows);
	}

	public function testNegativeZeroIsParenthesised(): void
	{
		$driver = new LegacyDriver($GLOBALS['db']);

		$driver->fetchAll(new BoundStatement('SELECT 10-? AS amount', [-0.0]));

		self::assertSame('SELECT 10-(-0.0) AS amount', $GLOBALS['db']->lastquery);
	}

	public function testPlaceholderCountMismatchThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new LegacyDriver($GLOBALS['db']))->fetchAll(new BoundStatement('SELECT ?, ?', [1]));
	}
}
