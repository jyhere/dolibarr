<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Database;

use Dolibarr\Core\Database\DatabaseClock;
use PHPUnit\Framework\TestCase;

/**
 * The comparison with the legacy $db->idate(dol_now()) needs Dolibarr loaded (unit tests stub dol_print_date()):
 * it is Integration/Database/DatabaseClockTest.
 */
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

	public function testNowIsTheServerWallClockLabelledUtc(): void
	{
		date_default_timezone_set('Europe/Paris');

		do {
			$before = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
			$now = DatabaseClock::now();
			$after = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
		} while ($before !== $after);

		self::assertSame($before, $now->format('Y-m-d H:i:s'));
		self::assertSame('UTC', $now->getTimezone()->getName());
	}
}
