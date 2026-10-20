<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Logger;
use Dolibarr\Module\User\Controller\LoginController;
use Dolibarr\Module\User\Security\Authenticator;
use Dolibarr\Module\User\Security\SessionAuthenticator;
use Dolibarr\Module\User\View\LegacyLoginPage;
use PHPUnit\Framework\TestCase;

final class LoginControllerTest extends TestCase
{
	/** @var array<mixed> */
	private array $session = [];

	protected function setUp(): void
	{
		$this->session = $_SESSION ?? [];
		$_SESSION = [];
	}

	protected function tearDown(): void
	{
		$_SESSION = $this->session;
	}

	public function testForcedPasswordChangeRedirectsToChangePasswordPage(): void
	{
		$ctx = LegacyContext::fromGlobals();
		$connection = new Connection($ctx->db);
		$row = $connection->fetchOne('SELECT force_pass_change FROM '.MAIN_DB_PREFIX.'user WHERE login = \''.$ctx->db->escape(self::env('DOLIBARR_ADMIN_LOGIN')).'\'');
		self::assertNotNull($row);
		$previous = (int) $row->force_pass_change;
		$ctx->db->query('UPDATE '.MAIN_DB_PREFIX.'user SET force_pass_change = 1 WHERE login = \''.$ctx->db->escape(self::env('DOLIBARR_ADMIN_LOGIN')).'\'');
		try {
			$response = $this->controller($ctx)->authenticate(new Request('POST', '/login', [], [
				'actionlogin' => 'login',
				'username' => self::env('DOLIBARR_ADMIN_LOGIN'),
				'password' => self::env('DOLIBARR_ADMIN_PASSWORD'),
				'backtopage' => '/user/1',
			], [], []));
		} finally {
			$ctx->db->query('UPDATE '.MAIN_DB_PREFIX.'user SET force_pass_change = '.$previous.' WHERE login = \''.$ctx->db->escape(self::env('DOLIBARR_ADMIN_LOGIN')).'\'');
		}

		self::assertSame(302, $response->status());
		self::assertSame('/user/changepassword.php', $response->headers()['Location']);
		self::assertSame(self::env('DOLIBARR_ADMIN_LOGIN'), $_SESSION['dol_login']);
	}

	public function testWithoutForcedPasswordChangeGoesToBacktopage(): void
	{
		$ctx = LegacyContext::fromGlobals();
		$response = $this->controller($ctx)->authenticate(new Request('POST', '/login', [], [
			'actionlogin' => 'login',
			'username' => self::env('DOLIBARR_ADMIN_LOGIN'),
			'password' => self::env('DOLIBARR_ADMIN_PASSWORD'),
			'backtopage' => '/user/1',
		], [], []));

		self::assertSame('/user/1', $response->headers()['Location']);
	}

	private function controller(LegacyContext $ctx): LoginController
	{
		$hooks = new LegacyHooks($ctx->hookmanager);
		$dispatcher = new EventDispatcher();

		return new LoginController(
			$ctx,
			new Authenticator($ctx, explode(',', (string) $ctx->conf->file->main_authentication)),
			new SessionAuthenticator($ctx, $dispatcher, $hooks, new Connection($ctx->db), new Logger()),
			$dispatcher,
			$hooks,
			new LegacyLoginPage($ctx),
			new Logger(),
			''
		);
	}

	private static function env(string $name): string
	{
		$value = getenv($name);
		self::assertIsString($value, $name.' must be set in the php-fpm container environment');

		return $value;
	}
}
