<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Legacy\Redirect;

use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirectTable;
use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Tests\Unit\Fixture\FixtureModule;
use Dolibarr\Tests\Unit\Routing\Fixture\FakeController;
use PHPUnit\Framework\TestCase;

final class LegacyRedirectTest extends TestCase
{
	public function testUnknownRouteInDeclarationThrows(): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('nowhere');
		$this->expectExceptionMessage('stub');
		LegacyRedirectTable::fromModules([$this->module([new LegacyRedirect('user/card.php', 'nowhere')])], $this->routes());
	}

	public function testParamMapTargetMustBeARouteVariable(): void
	{
		$this->expectException(\LogicException::class);
		LegacyRedirectTable::fromModules([$this->module([new LegacyRedirect('user/card.php', 'user_show', ['id' => 'ident'])])], $this->routes());
	}

	public function testFixedParamKeyMustBeARouteVariable(): void
	{
		$this->expectException(\LogicException::class);
		LegacyRedirectTable::fromModules([$this->module([new LegacyRedirect('user/card.php', 'user_show', ['id' => 'id'], ['entity' => 'user'])])], $this->routes());
	}

	public function testInvalidRequirementRegexThrows(): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('user/card.php');
		LegacyRedirectTable::fromModules([$this->module([new LegacyRedirect('user/card.php', 'user_show', ['id' => 'id'], requirements: ['id' => '(\d+'])])], $this->routes());
	}

	public function testRulesAreIndexedByScriptInDeclarationOrder(): void
	{
		$byId = new LegacyRedirect('user/card.php', 'user_show', ['id' => 'id']);
		$byRowid = new LegacyRedirect('user/card.php', 'user_show', ['rowid' => 'id']);
		$list = new LegacyRedirect('user/list.php', 'user_list');
		$table = LegacyRedirectTable::fromModules([$this->module([$byId, $list, $byRowid])], $this->routes());

		self::assertEquals([$byId, $byRowid], $table->forScript('user/card.php'));
		self::assertSame([], $table->forScript('other.php'));
		self::assertEquals($table, LegacyRedirectTable::fromArray($table->toArray()));
	}

	public function testExistingModulesDeclareNothing(): void
	{
		self::assertSame([], (new FixtureModule())->legacyRedirects());
	}

	/**
	 * @return list<\Dolibarr\Core\Routing\RouteDefinition>
	 */
	private function routes(): array
	{
		return (new RouteCompiler())->compile([FakeController::class]);
	}

	/**
	 * @param list<LegacyRedirect> $rules
	 */
	private function module(array $rules): ModuleInterface
	{
		return new StubRedirectModule($rules);
	}
}
