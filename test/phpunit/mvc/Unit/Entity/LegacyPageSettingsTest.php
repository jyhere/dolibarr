<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Entity;

use Dolibarr\Core\Entity\LegacyPageSettings;
use PHPUnit\Framework\TestCase;

final class LegacyPageSettingsTest extends TestCase
{
	public function testHoldsTheValues(): void
	{
		$settings = new LegacyPageSettings(['companies'], ['thirdpartynote', 'globalcard'], ['id' => 3]);

		self::assertSame(['companies'], $settings->translationDomains);
		self::assertSame(['thirdpartynote', 'globalcard'], $settings->hookContexts);
		self::assertSame(['id' => 3], $settings->doActionsParams);
		self::assertSame([], (new LegacyPageSettings([], []))->doActionsParams);
	}

	public function testEmptyNameIsRefused(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new LegacyPageSettings(['ecm', ''], []);
	}

	public function testNonListIsRefused(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new LegacyPageSettings([], ['a' => 'usercard']); // @phpstan-ignore argument.type
	}
}
