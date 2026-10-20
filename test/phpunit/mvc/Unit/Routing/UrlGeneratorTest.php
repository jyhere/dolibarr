<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing;

use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Tests\Unit\Routing\Fixture\FakeController;
use PHPUnit\Framework\TestCase;

final class UrlGeneratorTest extends TestCase
{
	private function generator(string $urlRoot): UrlGenerator
	{
		return new UrlGenerator((new RouteCompiler())->compile([FakeController::class]), $urlRoot);
	}

	public function testGenerateWithUrlRootAndExtraQuery(): void
	{
		self::assertSame('/dolibarr/user/7?tab=note', $this->generator('/dolibarr')->generate('user_show', ['id' => 7, 'tab' => 'note']));
	}

	public function testGenerateStaticRoute(): void
	{
		self::assertSame('/user', $this->generator('')->generate('user_list'));
	}

	public function testMissingPathVariableThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->generator('')->generate('user_show');
	}

	public function testUnknownRouteThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->generator('')->generate('nope');
	}
}
