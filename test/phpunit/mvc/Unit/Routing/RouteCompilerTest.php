<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing;

use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\RouteDefinition;
use Dolibarr\Tests\Unit\Routing\Fixture\DuplicateNameController;
use Dolibarr\Tests\Unit\Routing\Fixture\FakeController;
use Dolibarr\Tests\Unit\Routing\Fixture\MethodsController;
use Dolibarr\Tests\Unit\Routing\Fixture\UnnamedController;
use PHPUnit\Framework\TestCase;

final class RouteCompilerTest extends TestCase
{
	public function testCompilesStaticAndDynamicRoutes(): void
	{
		$defs = (new RouteCompiler())->compile([FakeController::class]);
		$byName = array_column(array_map(static fn(RouteDefinition $d): array => $d->toArray(), $defs), null, 'name');
		self::assertNull($byName['user_list']['regex']);
		self::assertSame('#^/user/(?P<id>\d+)$#', $byName['user_show']['regex']);
		self::assertSame(['id'], $byName['user_show']['variables']);
		self::assertTrue($byName['login']['public']);
		self::assertSame(['GET', 'POST'], $byName['login']['methods']);
		self::assertSame(FakeController::class, $byName['login']['controller']);
		self::assertSame('login', $byName['login']['action']);
	}

	public function testRepeatedAttributesDefaultNameRequirementAndEscaping(): void
	{
		$defs = (new RouteCompiler())->compile([UnnamedController::class]);
		self::assertCount(2, $defs);
		self::assertSame('unnamed_list', $defs[0]->name);
		self::assertSame('#^/a\.b/(?P<slug>[^/]+)$#', $defs[0]->regex);
		self::assertSame('other', $defs[1]->name);
		self::assertNull($defs[1]->regex);
	}

	public function testArrayRoundTrip(): void
	{
		foreach ((new RouteCompiler())->compile([FakeController::class]) as $d) {
			self::assertEquals($d, RouteDefinition::fromArray($d->toArray()));
		}
	}

	public function testDuplicateRouteNamesAreRefusedNamingBothMethods(): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessageMatches('/"same".*DuplicateNameController::first\(\).*DuplicateNameController::second\(\)/');
		(new RouteCompiler())->compile([DuplicateNameController::class]);
	}

	public function testDuplicateRouteNamesAcrossControllersAreRefused(): void
	{
		$this->expectException(\LogicException::class);
		(new RouteCompiler())->compile([FakeController::class, FakeController::class]);
	}

	public function testMethodsAreUppercased(): void
	{
		$byName = [];
		foreach ((new RouteCompiler())->compile([MethodsController::class]) as $d) {
			$byName[$d->name] = $d;
		}
		self::assertSame(['GET', 'POST'], $byName['lower']->methods);
		self::assertSame(['HEAD'], $byName['probe_head']->methods);
	}
}
