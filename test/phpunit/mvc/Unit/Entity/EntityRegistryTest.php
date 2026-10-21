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

	public function testFindByElement(): void
	{
		$registry = new EntityRegistry();
		$definition = new EntityDefinition('thirdparty', \stdClass::class, 'repo', 'societe', 'societe', 'checker', 'tabs', 'thirdparty_show');
		$registry->register($definition);

		self::assertSame($definition, $registry->findByElement('societe'));
		self::assertNull($registry->findByElement('thirdparty'));
	}

	public function testDuplicateSlugThrows(): void
	{
		$registry = new EntityRegistry();
		$registry->register(new EntityDefinition('user', \stdClass::class, 'repo', 'user', 'user', 'checker', 'tabs', 'user_show'));

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('user');
		$registry->register(new EntityDefinition('user', \stdClass::class, 'other', 'user', 'user', 'checker', 'tabs', 'user_show'));
	}

	public function testTwoDefinitionsOfTheSameElementThrow(): void
	{
		$registry = new EntityRegistry();
		$registry->register(new EntityDefinition('user', \stdClass::class, 'repo', 'user', 'user', 'checker', 'tabs', 'user_show'));
		$registry->register(new EntityDefinition('member_user', \stdClass::class, 'repo', 'user', 'user', 'checker', 'tabs', 'user_show'));
		self::assertNull($registry->findByElement('societe'));

		$this->expectException(\LogicException::class);
		$registry->findByElement('user');
	}

	public function testUnknownSlugThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new EntityRegistry())->get('nope');
	}
}
