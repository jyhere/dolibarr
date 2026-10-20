<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Container;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Container\ContainerException;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
	public function testFactoryIsCalledOnce(): void
	{
		$calls = 0;
		$container = new Container();
		$container->set(\stdClass::class, static function (Container $c) use (&$calls): object {
			$calls++;
			return new \stdClass();
		});

		$first = $container->get(\stdClass::class);
		$second = $container->get(\stdClass::class);

		$this->assertSame($first, $second);
		$this->assertSame(1, $calls);
	}

	public function testFactoryReceivesContainer(): void
	{
		$received = null;
		$container = new Container();
		$container->set(\stdClass::class, static function (Container $c) use (&$received): object {
			$received = $c;
			return new \stdClass();
		});

		$container->get(\stdClass::class);

		$this->assertSame($container, $received);
	}

	public function testUnknownIdThrows(): void
	{
		$container = new Container();

		$this->assertFalse($container->has('Unknown'));
		$this->expectException(ContainerException::class);
		$container->get('Unknown');
	}

	public function testCircularDependencyThrowsWithChain(): void
	{
		$container = new Container();
		$container->set('A', static fn(Container $c): object => $c->get('B'));
		$container->set('B', static fn(Container $c): object => $c->get('A'));

		$this->expectException(ContainerException::class);
		$this->expectExceptionMessage('A -> B -> A');
		$container->get('A');
	}

	public function testInstanceRegistersPrebuiltObject(): void
	{
		$object = new \stdClass();
		$container = new Container();
		$container->instance(\stdClass::class, $object);

		$this->assertTrue($container->has(\stdClass::class));
		$this->assertSame($object, $container->get(\stdClass::class));
	}
}
