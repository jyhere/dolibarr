<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirectBootstrap;
use Dolibarr\Tests\Unit\Legacy\Redirect\StubRedirectModule;
use Dolibarr\Tests\Unit\Routing\Fixture\FakeController;
use PHPUnit\Framework\TestCase;

final class LegacyRedirectBootstrapTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir().'/mvc-redirect-'.bin2hex(random_bytes(4));
		mkdir($this->dir, 0775, true);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir.'/cache/*') ?: [] as $f) {
			unlink($f);
		}
		@rmdir($this->dir.'/cache');
		@rmdir($this->dir);
	}

	/**
	 * @return list<StubRedirectModule>
	 */
	private function modules(string $route = 'user_show'): array
	{
		return [new StubRedirectModule([new LegacyRedirect('user/card.php', $route, ['id' => 'id'], required: ['id'], requirements: ['id' => '\d+'])], [FakeController::class])];
	}

	/**
	 * @param array<string,string> $query
	 */
	private function target(array $query, bool $enabled = true, ?array $modules = null, bool $prod = false): ?string
	{
		return LegacyRedirectBootstrap::target('GET', 'user/card.php', $query, [], $enabled, $modules ?? $this->modules(), $this->dir, $prod);
	}

	public function testRedirectsWhenEnabled(): void
	{
		self::assertSame(DOL_URL_ROOT.'/user/5', $this->target(['id' => '5']));
		self::assertFileExists($this->dir.'/cache/legacy_redirects.php');
		self::assertFileExists($this->dir.'/cache/routes.php');
		// Second call served from the caches (dev freshness, then prod)
		self::assertSame(DOL_URL_ROOT.'/user/5', $this->target(['id' => '5']));
		self::assertSame(DOL_URL_ROOT.'/user/5', $this->target(['id' => '5'], true, null, true));
	}

	public function testDisabledByConfReturnsNull(): void
	{
		self::assertNull($this->target(['id' => '5'], LegacyRedirectBootstrap::isEnabled('0', '1')));
		self::assertTrue(LegacyRedirectBootstrap::isEnabled('1', '0'), 'conf.php wins over the constant');
		self::assertTrue(LegacyRedirectBootstrap::isEnabled(null, '1'));
		$source = (string) file_get_contents(__DIR__.'/../../../../../htdocs/src/legacy_redirect.inc.php');
		self::assertStringContainsString('$dolibarr_main_mvc_legacy_redirects', $source);
	}

	public function testDisabledByConstReturnsNull(): void
	{
		self::assertNull($this->target(['id' => '5'], LegacyRedirectBootstrap::isEnabled(null, '0')));
		self::assertFalse(LegacyRedirectBootstrap::isEnabled(null, '0'));
		$source = (string) file_get_contents(__DIR__.'/../../../../../htdocs/src/legacy_redirect.inc.php');
		self::assertStringContainsString("getDolGlobalString('MAIN_MVC_LEGACY_REDIRECTS', '1')", $source);
	}

	public function testFrontControllerNeverRedirects(): void
	{
		$root = __DIR__.'/../../../../../htdocs';
		$main = (string) file_get_contents($root.'/main.inc.php');
		self::assertMatchesRegularExpression("/if \\(!defined\\(\\s*'DOL_MVC_FRONT'\\s*\\)[^\\n]*legacy_redirect\\.inc\\.php/", $main);
		$app = (string) file_get_contents($root.'/app.php');
		$define = preg_match("/define\\(\\s*'DOL_MVC_FRONT'/", $app, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : false;
		$require = preg_match("/require\\s+__DIR__\\s*\\.\\s*'\\/main\\.inc\\.php'/", $app, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : false;
		self::assertNotFalse($define);
		self::assertNotFalse($require);
		self::assertLessThan($require, $define);
	}

	public function testWarmCacheWithoutRuleForScriptDoesNotLoadRoutes(): void
	{
		self::assertSame(DOL_URL_ROOT.'/user/5', $this->target(['id' => '5'])); // builds the caches
		$calls = 0;
		$spy = function () use (&$calls): array {
			$calls++;

			return (new \Dolibarr\Core\Routing\RouteCompiler())->compile([FakeController::class]);
		};
		foreach ([false, true] as $prod) {
			self::assertNull(LegacyRedirectBootstrap::target('GET', 'other/page.php', ['id' => '5'], [], true, $this->modules(), $this->dir, $prod, $spy));
			self::assertSame(0, $calls, 'no rule for the script: routes not loaded (prod='.var_export($prod, true).')');
		}
		self::assertSame(DOL_URL_ROOT.'/user/5', LegacyRedirectBootstrap::target('GET', 'user/card.php', ['id' => '5'], [], true, $this->modules(), $this->dir, true, $spy));
		self::assertSame(1, $calls);
	}

	public function testNoRedirectWhenHeadersAlreadySent(): void
	{
		self::assertFalse(LegacyRedirectBootstrap::emit('/user/5', static fn (): bool => true));
	}

	public function testExceptionFallsBackToLegacy(): void
	{
		// Unknown route in a rule: the table cannot be built
		self::assertNull($this->target(['id' => '5'], true, $this->modules('no_such_route')));
		// Unparsable cache file in production
		is_dir($this->dir.'/cache') or mkdir($this->dir.'/cache', 0775, true);
		file_put_contents($this->dir.'/cache/routes.php', '<?php this is not php');
		self::assertNull($this->target(['id' => '5'], true, null, true));
	}

	public function testScriptPathWithUrlRoot(): void
	{
		self::assertSame('user/card.php', LegacyRedirectBootstrap::normalizeScript('/dolibarr/user/card.php', '/dolibarr'));
		self::assertSame('user/card.php', LegacyRedirectBootstrap::normalizeScript('/user/card.php', ''));
		self::assertNull(LegacyRedirectBootstrap::normalizeScript('/dolibarr2/user/card.php', '/dolibarr'));
		self::assertNull(LegacyRedirectBootstrap::normalizeScript('/user/card.php', '/dolibarr'));
	}

	public function testScriptNormalisationRejectsHostilePaths(): void
	{
		foreach (['/user/../user/card.php', '/user\\card.php', '//user/card.php', "/user/card.php\0.png", '/user/card.txt', '/', ''] as $script) {
			self::assertNull(LegacyRedirectBootstrap::normalizeScript($script, ''), var_export($script, true));
		}
	}

	public function testHeadersRebuiltLikeRequest(): void
	{
		self::assertSame(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'a'], LegacyRedirectBootstrap::headersFromServer(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'a', 'REQUEST_METHOD' => 'GET']));
		self::assertNull(LegacyRedirectBootstrap::target('GET', 'user/card.php', ['id' => '5'], LegacyRedirectBootstrap::headersFromServer(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), true, $this->modules(), $this->dir, false));
	}
}
