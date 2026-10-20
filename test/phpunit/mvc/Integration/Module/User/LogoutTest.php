<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Module\User\Controller\LogoutController;
use Dolibarr\Module\User\Event\UserLoggedOut;
use PHPUnit\Framework\TestCase;

final class LogoutTest extends TestCase
{
	/** @var array<mixed> */
	private array $session = [];

	protected function setUp(): void
	{
		$this->session = $_SESSION ?? [];
	}

	protected function tearDown(): void
	{
		$_SESSION = $this->session;
	}

	public function testLogoutDestroysSessionAndRedirectsToIndex(): void
	{
		$_SESSION = ['dol_login' => $GLOBALS['user']->login, 'dol_entity' => 1, 'urlfrom' => ''];

		$response = $this->controller()->logout(new Request('POST', '/logout', [], [], [], []));

		self::assertSame(302, $response->status());
		self::assertStringEndsWith('/index.php', $response->headers()['Location']);
		self::assertArrayNotHasKey('dol_login', $_SESSION);
		self::assertArrayNotHasKey('dol_entity', $_SESSION);
		self::assertArrayNotHasKey('urlfrom', $_SESSION);
	}

	public function testLogoutDispatchesUserLoggedOut(): void
	{
		$_SESSION = ['dol_login' => $GLOBALS['user']->login, 'dol_entity' => 1];
		$dispatcher = new EventDispatcher();
		$received = [];
		$dispatcher->addListener(UserLoggedOut::class, static function (UserLoggedOut $e) use (&$received): void {
			$received[] = $e;
		});

		$this->controller($dispatcher)->logout(new Request('POST', '/logout', [], [], [], []));

		self::assertCount(1, $received);
		self::assertSame($GLOBALS['user'], $received[0]->user);
	}

	public function testInvalidTokenCancelsTheLogout(): void
	{
		// What main.inc.php leaves when the posted token is invalid: $_POST emptied, errorcode set
		$_SESSION = ['dol_login' => 'admin', 'dol_entity' => 1];
		$dispatcher = new EventDispatcher();
		$received = 0;
		$dispatcher->addListener(UserLoggedOut::class, static function () use (&$received): void {
			$received++;
		});

		$response = $this->controller($dispatcher)->logout(new Request('POST', '/logout', ['errorcode' => 'InvalidToken'], [], [], []));

		self::assertSame(302, $response->status());
		self::assertSame('/index.php', $response->headers()['Location']);
		self::assertSame(0, $received);
		self::assertSame('admin', $_SESSION['dol_login']);
	}

	public function testNoRedirectReturnsAnEmptyResponse(): void
	{
		$_SESSION = ['dol_login' => $GLOBALS['user']->login];

		$response = $this->controller()->logout(new Request('POST', '/logout', [], ['noredirect' => '1'], [], []));

		self::assertSame(200, $response->status());
		self::assertArrayNotHasKey('Location', $response->headers());
		self::assertArrayNotHasKey('dol_login', $_SESSION);
	}

	public function testForcedAuthenticationModeOnlyClearsTheSessionKeys(): void
	{
		$_SESSION = ['dol_login' => 'admin', 'dol_entity' => 1, 'urlfrom' => '/x', 'dol_authmode' => 'http'];
		$dispatcher = new EventDispatcher();
		$received = 0;
		$dispatcher->addListener(UserLoggedOut::class, static function () use (&$received): void {
			$received++;
		});

		$response = $this->controller($dispatcher)->logout(new Request('GET', '/logout', [], [], [], []));

		self::assertSame(200, $response->status());
		self::assertSame(0, $received);
		self::assertArrayNotHasKey('dol_login', $_SESSION);
		self::assertArrayNotHasKey('urlfrom', $_SESSION);
	}

	public function testSessionUrlfromAndMenuFlagsBuildTheTarget(): void
	{
		$_SESSION = ['dol_login' => 'admin', 'urlfrom' => '/comm/index.php'];

		$response = $this->controller(null, '/dolibarr')->logout(new Request('GET', '/logout', ['dol_hide_topmenu' => '1'], [], [], []));

		self::assertSame('/dolibarr/comm/index.php?dol_hide_topmenu=1', $response->headers()['Location']);
	}

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function urlfromProvider(): iterable
	{
		yield 'protocol relative' => ['//evil.example/x', '/index.php'];
		yield 'absolute url' => ['https://evil', '/index.php'];
		yield 'backslash' => ['/\\evil', '/index.php'];
		yield 'javascript' => ['javascript:alert(1)', '/index.php'];
		yield 'local path' => ['/user/1', '/user/1'];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('urlfromProvider')]
	public function testRequestUrlfromIsSanitisedAndMustStayOnTheInstance(string $urlfrom, string $expected): void
	{
		$_SESSION = ['dol_login' => 'admin'];

		$response = $this->controller()->logout(new Request('GET', '/logout', ['urlfrom' => $urlfrom], [], [], []));

		self::assertSame($expected, $response->headers()['Location']);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('urlfromProvider')]
	public function testSessionUrlfromIsSanitisedAndMustStayOnTheInstance(string $urlfrom, string $expected): void
	{
		$_SESSION = ['dol_login' => 'admin', 'urlfrom' => $urlfrom];

		$response = $this->controller()->logout(new Request('GET', '/logout', [], [], [], []));

		self::assertSame($expected, $response->headers()['Location']);
	}

	public function testUrlfromUnderAUrlRoot(): void
	{
		$_SESSION = ['dol_login' => 'admin'];

		$response = $this->controller(null, '/dolibarr')->logout(new Request('GET', '/logout', ['urlfrom' => '//evil.example/x'], [], [], []));

		self::assertSame('/dolibarr/index.php', $response->headers()['Location']);
	}

	private function controller(?EventDispatcher $dispatcher = null, string $urlRoot = ''): LogoutController
	{
		return new LogoutController(
			LegacyContext::fromGlobals(),
			$dispatcher ?? new EventDispatcher(),
			new LegacyHooks($GLOBALS['hookmanager']),
			$urlRoot,
			'http://localhost'
		);
	}
}
