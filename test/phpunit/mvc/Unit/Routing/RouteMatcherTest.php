<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing;

use Dolibarr\Core\Routing\MethodNotAllowedException;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\RouteMatcher;
use Dolibarr\Core\Routing\RouteNotFoundException;
use Dolibarr\Tests\Unit\Routing\Fixture\FakeController;
use Dolibarr\Tests\Unit\Routing\Fixture\MethodsController;
use PHPUnit\Framework\TestCase;

final class RouteMatcherTest extends TestCase
{
	private function matcher(): RouteMatcher
	{
		return new RouteMatcher((new RouteCompiler())->compile([FakeController::class]));
	}

	public function testMatchesStaticRoute(): void
	{
		$match = $this->matcher()->match('POST', '/login');
		self::assertSame('login', $match->route->name);
		self::assertSame([], $match->params);
	}

	public function testMatchesDynamicRouteAndExtractsParams(): void
	{
		$match = $this->matcher()->match('GET', '/user/42');
		self::assertSame(['id' => '42'], $match->params);
		self::assertSame('user_show', $match->route->name);
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

	public function testLowercaseDeclaredMethodsMatch(): void
	{
		$matcher = new RouteMatcher((new RouteCompiler())->compile([MethodsController::class]));
		self::assertSame('lower', $matcher->match('post', '/lower')->route->name);
	}

	public function testExplicitHeadRouteWinsOverTheGetFallback(): void
	{
		$matcher = new RouteMatcher((new RouteCompiler())->compile([MethodsController::class]));
		self::assertSame('probe_head', $matcher->match('HEAD', '/probe')->route->name);
		self::assertSame('probe_get', $matcher->match('GET', '/probe')->route->name);
	}

	public function testHeadFallsBackToGet(): void
	{
		self::assertSame('user_show', $this->matcher()->match('HEAD', '/user/42')->route->name);
	}
}
