<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Database;

use Dolibarr\Core\Database\DatabaseClock;
use PHPUnit\Framework\TestCase;

final class DatabaseClockTest extends TestCase
{
	private string $timezone;

	protected function setUp(): void
	{
		$this->timezone = date_default_timezone_get();
	}

	protected function tearDown(): void
	{
		date_default_timezone_set($this->timezone);
	}

	/**
	 * The legacy writes "now" with $db->idate(dol_now()): the server wall clock, stored without zone.
	 * DatabaseClock gives that same value, labelled UTC as the dates read back.
	 */
	public function testNowMatchesLegacyIdate(): void
	{
		date_default_timezone_set('Europe/Paris');
		$db = $GLOBALS['db'];

		do {
			$before = $db->idate(dol_now());
			$now = DatabaseClock::now();
			$after = $db->idate(dol_now());
		} while ($before !== $after);

		self::assertSame($before, $now->format('Y-m-d H:i:s'));
		self::assertSame('UTC', $now->getTimezone()->getName());
		self::assertNotSame(gmdate('Y-m-d H'), $now->format('Y-m-d H'), 'Europe/Paris is never UTC');
	}
}
