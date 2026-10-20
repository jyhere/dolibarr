<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
	public function testLatteIsLoadable(): void
	{
		self::assertTrue(class_exists(\Latte\Engine::class));
	}

	public function testDolibarrNamespaceMapsToSrc(): void
	{
		self::assertTrue(class_exists(\Dolibarr\Tests\Unit\Fixture\Probe::class));
	}

	public function testUnknownClassReturnsFalseWithoutError(): void
	{
		self::assertFalse(class_exists('Dolibarr\\Nope\\Missing'));
	}
}
