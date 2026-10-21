<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\View;

use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Tests\Unit\View\Fixture\FakeCurrentUser;
use Dolibarr\Tests\Unit\View\Fixture\FakeTranslator;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Fixture/legacy_date_stub.php';

final class DateFilterTest extends TestCase
{
	public function testNullPrintsNothing(): void
	{
		self::assertSame('', ($this->filter())(null));
	}

	public function testDateObjectIsPrintedFromItsTimestamp(): void
	{
		$date = new \DateTimeImmutable('2026-10-20 00:00:00', new \DateTimeZone('UTC'));
		self::assertSame('DATE('.$date->getTimestamp().',day,gmt)', ($this->filter())($date, 'day', 'gmt'));
	}

	public function testTimestampKeepsDefaults(): void
	{
		self::assertSame('DATE(86400,day,auto)', ($this->filter())(86400));
	}

	private function filter(): callable
	{
		$filters = (new DolibarrExtension(new FakeTranslator(), new FakeCurrentUser(), new UrlGenerator([], '')))->getFilters();

		return $filters['date'];
	}
}
