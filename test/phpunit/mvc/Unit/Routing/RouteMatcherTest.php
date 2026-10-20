<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing;

use Dolibarr\Core\Routing\MethodNotAllowedException;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\RouteMatcher;
use Dolibarr\Core\Routing\RouteNotFoundException;
use Dolibarr\Tests\Unit\Routing\Fixture\FakeController;
use PHPUnit\Framework\TestCase;

final class RouteMatcherTest extends TestCase
{
	private function matcher(): RouteMatcher
	{
		return new RouteMatcher((new RouteCompiler())->compile([FakeController::class]));
	}

	public function testMatchesStaticRoute(): void
	{
		$m = $this->matcher()->match('POST', '/login');
		self::assertSame('login', $m->route->name);
		self::assertSame([], $m->params);
	}

	public function testMatchesDynamicRouteAndExtractsParams(): void
	{
		$m = $this->matcher()->match('GET', '/user/42');
		self::assertSame(['id' => '42'], $m->params);
		self::assertSame('user_show', $m->route->name);
	}

	public function testNonNumericIdIsNotFound(): void
	{
		$this->expectException(RouteNotFoundException::class);
		$this->matcher()->match('GET', '/user/abc');
	}

	public function testUnknownPathIsNotFound(): void
	{
		$this->expectException(RouteNotFoundException::class);
		$this->matcher()->match('GET', '/nope');
	}

	public function testWrongMethodIs405WithAllowedList(): void
	{
		try {
			$this->matcher()->match('DELETE', '/user');
			self::fail('Expected MethodNotAllowedException');
		} catch (MethodNotAllowedException $e) {
			self::assertSame(['GET'], $e->allowed());
		}
	}

	public function testWrongMethodOnDynamicRouteIs405(): void
	{
		$this->expectException(MethodNotAllowedException::class);
		$this->matcher()->match('POST', '/user/42');
	}

	public function testHeadIsTreatedAsGet(): void
	{
		self::assertSame('user_list', $this->matcher()->match('HEAD', '/user')->route->name);
	}
}
