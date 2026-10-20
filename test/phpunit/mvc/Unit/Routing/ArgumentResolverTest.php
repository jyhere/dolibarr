<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing;

use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Routing\ArgumentResolver;
use Dolibarr\Core\Routing\MatchedRoute;
use Dolibarr\Core\Routing\RouteDefinition;
use Dolibarr\Tests\Unit\Routing\Fixture\ArgumentsController;
use PHPUnit\Framework\TestCase;

final class ArgumentResolverTest extends TestCase
{
	public function testRequestAndRouteVariablesAreCastToDeclaredTypes(): void
	{
		$request = new Request('GET', '/x', [], [], [], []);
		$args = $this->resolve('typed', ['id' => '42', 'ratio' => '1.5', 'flag' => 'yes', 'slug' => 'abc'], $request);

		self::assertSame([$request, 42, 1.5, true, 'abc'], $args);
	}

	public function testFalseLikeBoolean(): void
	{
		$args = $this->resolve('typed', ['id' => '1', 'ratio' => '0', 'flag' => 'off', 'slug' => 's']);

		self::assertFalse($args[3]);
	}

	public function testDefaultValueThenNullForNonRouteParameters(): void
	{
		self::assertSame([7, 'view', null, null], $this->resolve('defaults', ['id' => '7']));
	}

	public function testUnresolvableParameterThrows(): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('$missing');
		$this->resolve('unresolvable', []);
	}

	/**
	 * @param array<string,string> $params
	 * @return list<mixed>
	 */
	private function resolve(string $action, array $params, ?Request $request = null): array
	{
		$definition = new RouteDefinition('n', '/x', ['GET'], ArgumentsController::class, $action, [], false, null, []);

		return (new ArgumentResolver())->resolve(
			new \ReflectionMethod(ArgumentsController::class, $action),
			new MatchedRoute($definition, $params),
			$request ?? new Request('GET', '/x', [], [], [], [])
		);
	}
}
