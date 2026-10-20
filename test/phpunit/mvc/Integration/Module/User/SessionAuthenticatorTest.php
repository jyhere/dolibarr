<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacySession;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Logger;
use Dolibarr\Module\User\Event\UserLoggedIn;
use Dolibarr\Module\User\Listener\LegacyTriggerBridge;
use Dolibarr\Module\User\Security\AuthenticationException;
use Dolibarr\Module\User\Security\SessionAuthenticator;
use PHPUnit\Framework\TestCase;

final class SessionAuthenticatorTest extends TestCase
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

	public function testLoginWritesExactlyTheMainIncSessionKeys(): void
	{
		$_SESSION = [];
		$this->sessionAuthenticator()->login($GLOBALS['user'], 'dolibarr', ['tz' => '2', 'screenwidth' => '1920', 'dol_hide_topmenu' => '']);
		self::assertSame(['dol_login', 'dol_logindate', 'dol_authmode', 'dol_tz', 'dol_tz_string', 'dol_dst', 'dol_dst_observed', 'dol_dst_first', 'dol_dst_second', 'dol_screenwidth', 'dol_screenheight', 'dol_company', 'dol_entity'], array_keys($_SESSION));
		self::assertSame($GLOBALS['user']->login, $_SESSION['dol_login']);
		self::assertSame('2', $_SESSION['dol_tz']);
		self::assertSame($GLOBALS['conf']->entity, $_SESSION['dol_entity']);
		self::assertSame('dolibarr', $_SESSION['dol_authmode']);
		self::assertSame('1920', $_SESSION['dol_screenwidth']);
	}

	public function testLoginStoresTheOptionalKeysOnlyWhenPostedAndNotInPopup(): void
	{
		$_SESSION = [];
		$this->sessionAuthenticator()->login($GLOBALS['user'], 'dolibarr', ['dol_hide_topmenu' => '1', 'dol_hide_leftmenu' => '1', 'dol_optimize_smallscreen' => '1', 'dol_openinpopup' => 'abc']);
		self::assertArrayNotHasKey('dol_hide_topmenu', $_SESSION);
		self::assertArrayNotHasKey('dol_hide_leftmenu', $_SESSION);
		self::assertSame(1, $_SESSION['dol_optimize_smallscreen']);

		$_SESSION = [];
		$this->sessionAuthenticator()->login($GLOBALS['user'], 'dolibarr', ['dol_hide_topmenu' => '1']);
		self::assertSame(1, $_SESSION['dol_hide_topmenu']);
	}

	public function testLoginDispatchesUserLoggedInAndRunsTriggers(): void
	{
		$_SESSION = [];
		$ctx = LegacyContext::fromGlobals();
		// The Logevents trigger records USER_LOGIN only when this option is on: on for this test only
		$previous = $ctx->conf->global->MAIN_LOGEVENTS_USER_LOGIN ?? null;
		$ctx->conf->global->MAIN_LOGEVENTS_USER_LOGIN = 1;
		$before = $this->countLoginEvents($ctx);

		$dispatcher = new EventDispatcher();
		(new LegacyTriggerBridge(new LegacyTriggers($ctx), $ctx, new LegacyObjectLoader(new Connection($ctx->db)), new Logger()))->register($dispatcher);
		$received = [];
		$dispatcher->addListener(UserLoggedIn::class, static function (UserLoggedIn $e) use (&$received): void {
			$received[] = $e;
		});

		try {
			$this->sessionAuthenticator($dispatcher)->login($GLOBALS['user'], 'dolibarr', ['tz' => '2', 'screenwidth' => '1920', 'screenheight' => '1080']);
		} finally {
			if ($previous === null) {
				unset($ctx->conf->global->MAIN_LOGEVENTS_USER_LOGIN);
			} else {
				$ctx->conf->global->MAIN_LOGEVENTS_USER_LOGIN = $previous;
			}
		}

		self::assertCount(1, $received);
		self::assertSame((int) $GLOBALS['user']->id, $received[0]->userId);
		self::assertSame($GLOBALS['user']->login, $received[0]->login);
		self::assertSame('dolibarr', $received[0]->authMode);
		self::assertSame('TZ=2;TZString=;Screen=1920x1080 - authmode=dolibarr - entity='.$ctx->conf->entity, $received[0]->loginInfo);
		self::assertSame($before + 1, $this->countLoginEvents($ctx));
	}

	public function testAFailingUserLoggedInListenerRollsBackAndClearsTheSession(): void
	{
		$_SESSION = ['token' => 'abc'];
		$ctx = LegacyContext::fromGlobals();
		$before = $this->lastLoginDates($ctx);
		$dispatcher = new EventDispatcher();
		$dispatcher->addListener(UserLoggedIn::class, static function (): void {
			throw new \RuntimeException('USER_LOGIN trigger failed');
		});

		try {
			$this->sessionAuthenticator($dispatcher)->login($GLOBALS['user'], 'dolibarr', ['tz' => '2']);
			self::fail('AuthenticationException expected');
		} catch (AuthenticationException $e) {
			self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
		}

		self::assertSame($before, $this->lastLoginDates($ctx));
		self::assertArrayNotHasKey('dol_login', $_SESSION);
		self::assertArrayNotHasKey('dol_authmode', $_SESSION);
	}

	/**
	 * @return array{0: mixed, 1: mixed}
	 */
	private function lastLoginDates(LegacyContext $ctx): array
	{
		$row = (new Connection($ctx->db))->fetchOne('SELECT datelastlogin, datepreviouslogin FROM '.MAIN_DB_PREFIX.'user WHERE rowid = 1');
		self::assertNotNull($row);

		return [$row->datelastlogin, $row->datepreviouslogin];
	}

	private function countLoginEvents(LegacyContext $ctx): int
	{
		$row = (new Connection($ctx->db))->fetchOne("SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."events WHERE type = 'USER_LOGIN' AND fk_user = 1");
		self::assertNotNull($row);

		return (int) $row->nb;
	}

	private function sessionAuthenticator(?EventDispatcher $dispatcher = null): SessionAuthenticator
	{
		$ctx = LegacyContext::fromGlobals();

		return new SessionAuthenticator($ctx, new LegacySession(), new LegacyConfig($ctx), $dispatcher ?? new EventDispatcher(), new LegacyHooks($ctx->hookmanager), new Connection($ctx->db), new Logger());
	}
}
