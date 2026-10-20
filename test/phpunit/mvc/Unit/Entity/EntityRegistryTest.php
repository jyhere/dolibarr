<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Entity;

use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Entity\EntityRegistry;
use PHPUnit\Framework\TestCase;

final class EntityRegistryTest extends TestCase
{
	public function testRegisterAndGet(): void
	{
		$registry = new EntityRegistry();
		$definition = new EntityDefinition('user', \stdClass::class, 'repo', 'user', 'user', 'checker', 'tabs', 'user_show');
		self::assertFalse($registry->has('user'));

		$registry->register($definition);

		self::assertTrue($registry->has('user'));
		self::assertSame($definition, $registry->get('user'));
	}

	public function testUnknownSlugThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new EntityRegistry())->get('nope');
	}
}
