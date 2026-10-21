<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Legacy\Redirect;

use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirector;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirectTable;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Tests\Unit\Routing\Fixture\FakeController;
use PHPUnit\Framework\TestCase;

final class LegacyRedirectorTest extends TestCase
{
	public function testNoRedirectOnPost(): void
	{
		self::assertNull($this->redirector()->resolve('POST', 'user/card.php', ['id' => '5'], []));
	}

	public function testMethodIsCaseInsensitiveAndHeadAllowed(): void
	{
		self::assertSame('/user/5', $this->redirector()->resolve('get', 'user/card.php', ['id' => '5'], []));
		self::assertSame('/user/5', $this->redirector()->resolve('HEAD', 'user/card.php', ['id' => '5'], []));
	}

	public function testNoRedirectOnAjax(): void
	{
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => '5'], ['X-Requested-With' => 'XMLHttpRequest']));
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => '5'], ['x-requested-with' => 'xmlhttprequest']));
	}

	public function testActionMustBeAllowed(): void
	{
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => '5', 'action' => 'clone'], []));
		self::assertSame('/user/5', $this->redirector()->resolve('GET', 'user/card.php', ['id' => '5', 'action' => ' '], []));
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => '5', 'action' => ['x']], []));
	}

	public function testForbiddenParamBlocks(): void
	{
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => '5', 'massaction' => 'x'], []));
		self::assertSame('/user/5', $this->redirector()->resolve('GET', 'user/card.php', ['id' => '5', 'massaction' => ''], []));
	}

	public function testForbiddenValueBlocksOnlyThatValue(): void
	{
		$table = LegacyRedirectTable::fromModules([new StubRedirectModule([
			new LegacyRedirect('user/list.php', 'user_show', ['id' => 'id'], carry: ['contextpage'], forbiddenValues: ['contextpage' => ['employeelist']]),
		])], (new RouteCompiler())->compile([FakeController::class]));
		$redirector = new LegacyRedirector($table, new UrlGenerator((new RouteCompiler())->compile([FakeController::class]), ''));

		self::assertNull($redirector->resolve('GET', 'user/list.php', ['id' => '5', 'contextpage' => 'employeelist'], []));
		self::assertSame('/user/5?contextpage=userlist', $redirector->resolve('GET', 'user/list.php', ['id' => '5', 'contextpage' => 'userlist'], []));
		self::assertEquals($table, LegacyRedirectTable::fromArray($table->toArray()));
	}

	public function testForbiddenPrefixBlocksAnyMatchingKey(): void
	{
		$routes = (new RouteCompiler())->compile([FakeController::class]);
		$table = LegacyRedirectTable::fromModules([new StubRedirectModule([
			new LegacyRedirect('user/list.php', 'user_show', ['id' => 'id'], forbiddenPrefixes: ['search_options_']),
		])], $routes);
		$redirector = new LegacyRedirector($table, new UrlGenerator($routes, ''));

		self::assertNull($redirector->resolve('GET', 'user/list.php', ['id' => '5', 'search_options_x' => '1'], []));
		self::assertSame('/user/5', $redirector->resolve('GET', 'user/list.php', ['id' => '5', 'search_options_x' => '', 'search_x' => '1'], []));
		self::assertEquals($table, LegacyRedirectTable::fromArray($table->toArray()));
	}

	public function testRequiredParamMissing(): void
	{
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', [], []));
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => ''], []));
	}

	public function testRequirementRegex(): void
	{
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => 'abc'], []));
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => '5x'], []));
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => ['5']], []));
	}

	public function testMapsParamsAndCarriesWhitelistOnly(): void
	{
		self::assertSame(
			'/user/5?backtopage=%2Fx',
			$this->redirector()->resolve('GET', 'user/card.php', ['id' => '5', 'backtopage' => '/x', 'evil' => '1'], [])
		);
		self::assertSame('/user/5', $this->redirector()->resolve('GET', 'user/card.php', ['id' => '5', 'backtopage' => ['a']], []));
	}

	public function testQueryFromAction(): void
	{
		self::assertSame('/user/5?confirm=disable', $this->redirector()->resolve('GET', 'user/card.php', ['id' => '5', 'action' => 'disable'], []));
	}

	public function testFirstMatchingRuleWins(): void
	{
		$table = $this->table([
			new LegacyRedirect('x.php', 'user_list', required: ['a']),
			new LegacyRedirect('x.php', 'user_show', ['id' => 'id'], required: ['id']),
			new LegacyRedirect('x.php', 'user_list'),
		]);
		$r = new LegacyRedirector($table, new UrlGenerator($this->routes(), ''));

		self::assertSame('/user', $r->resolve('GET', 'x.php', ['a' => '1', 'id' => '2'], []));
		self::assertSame('/user/2', $r->resolve('GET', 'x.php', ['id' => '2'], []));
		self::assertSame('/user', $r->resolve('GET', 'x.php', [], []));
	}

	public function testUnknownScriptAndMissingRouteVariable(): void
	{
		self::assertNull($this->redirector()->resolve('GET', 'nope.php', [], []));
		$table = $this->table([new LegacyRedirect('y.php', 'user_show', ['id' => 'id'])]);
		$r = new LegacyRedirector($table, new UrlGenerator($this->routes(), ''));
		self::assertNull($r->resolve('GET', 'y.php', [], []));
	}

	public function testUrlRootPrefix(): void
	{
		$r = new LegacyRedirector($this->table($this->rules()), new UrlGenerator($this->routes(), '/dolibarr'));

		self::assertSame('/dolibarr/user/5', $r->resolve('GET', 'user/card.php', ['id' => '5'], []));
	}

	public function testFixedParamsFeedThePath(): void
	{
		$table = $this->table([new LegacyRedirect('z.php', 'user_show', fixedParams: ['id' => 7])]);
		$r = new LegacyRedirector($table, new UrlGenerator($this->routes(), ''));

		self::assertSame('/user/7', $r->resolve('GET', 'z.php', [], []));
	}

	public function testHostileValuesNeverRedirect(): void
	{
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => "5\n"], []));
		self::assertNull($this->redirector()->resolve('GET', 'user/card.php', ['id' => '1/../x'], []));

		$noRequirement = new LegacyRedirector($this->table([new LegacyRedirect('y.php', 'user_show', ['id' => 'id'])]), new UrlGenerator($this->routes(), ''));
		self::assertNull($noRequirement->resolve('GET', 'y.php', ['id' => ['5']], []));
		self::assertNull($noRequirement->resolve('GET', 'y.php', ['id' => '..'], []));
		self::assertNull($noRequirement->resolve('GET', 'y.php', ['id' => ' . '], []));
	}

	public function testCarriedValueIsEncoded(): void
	{
		self::assertSame(
			'/user/5?backtopage=a%0D%0Ab%2F%2Fevil',
			$this->redirector()->resolve('GET', 'user/card.php', ['id' => '5', 'backtopage' => "a\r\nb//evil"], [])
		);
	}

	private function redirector(): LegacyRedirector
	{
		return new LegacyRedirector($this->table($this->rules()), new UrlGenerator($this->routes(), ''));
	}

	/**
	 * @return list<LegacyRedirect>
	 */
	private function rules(): array
	{
		return [new LegacyRedirect(
			'user/card.php',
			'user_show',
			['id' => 'id'],
			[],
			['id'],
			['', 'disable'],
			['massaction', 'confirm'],
			['backtopage'],
			['disable' => ['confirm' => 'disable']],
			['id' => '\d+']
		)];
	}

	/**
	 * @param list<LegacyRedirect> $rules
	 */
	private function table(array $rules): LegacyRedirectTable
	{
		return LegacyRedirectTable::fromModules([new StubRedirectModule($rules)], $this->routes());
	}

	/**
	 * @return list<\Dolibarr\Core\Routing\RouteDefinition>
	 */
	private function routes(): array
	{
		return (new RouteCompiler())->compile([FakeController::class]);
	}
}
