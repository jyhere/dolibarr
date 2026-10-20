<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Controller;

use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Http\HtmlResponse;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\Response;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Routing\Route;
use Dolibarr\Module\User\Event\UserLoggedOut;

/**
 * Disconnection of the user, htdocs/user/logout.php.
 * Like the legacy page, urlfrom and MAIN_LOGOUT_GOTO_URL are trusted: logout.php applies no validation to them.
 */
final class LogoutController
{
	/** Display flags kept across the disconnection (logout.php l.88-107). */
	private const MENU_FLAGS = ['dol_hide_topmenu', 'dol_hide_leftmenu', 'dol_optimize_smallscreen', 'dol_no_mouse_hover', 'dol_use_jmobile'];

	public function __construct(
		private readonly LegacyContext $ctx,
		private readonly EventDispatcher $dispatcher,
		private readonly LegacyHooks $hooks,
		private readonly string $urlRoot,
		private readonly string $mainUrlRoot
	) {
	}

	/**
	 * GET stays for the legacy menu link, POST is the preferred method.
	 */
	#[Route('/logout', methods: ['GET', 'POST'], name: 'logout')]
	public function logout(Request $r): Response
	{
		// l.50-56: forced authentication modes, only the session keys are cleared
		if ($this->ctx->authMode() === 'forceuser' || $this->ctx->authMode() === 'http') {
			unset($_SESSION['dol_login'], $_SESSION['dol_entity'], $_SESSION['urlfrom']);

			return new HtmlResponse('');
		}

		// l.68-73: trigger USER_LOGOUT (through the LegacyTriggerBridge)
		$user = $this->ctx->user;
		if ($user !== null) {
			$this->dispatcher->dispatch(new UserLoggedOut($user));
		}

		// l.75-82: hooks on logout
		$action = '';
		$object = $user;
		$this->hooks->init(['logout']);
		$this->hooks->execute('afterLogout', [], $object, $action);

		// l.84-100: url to go after the disconnection
		$sessionUrlfrom = $_SESSION['urlfrom'] ?? '';
		$urlfrom = is_string($sessionUrlfrom) && $sessionUrlfrom !== '' ? $sessionUrlfrom : self::param($r, 'urlfrom');
		$url = $this->urlRoot.'/index.php';
		if ($urlfrom !== '') {
			$url = $this->urlRoot.$urlfrom;
		}
		$gotoUrl = getDolGlobalString('MAIN_LOGOUT_GOTO_URL');
		if ($gotoUrl !== '') {
			$url = $gotoUrl;
		}
		foreach (self::MENU_FLAGS as $flag) {
			if (self::param($r, $flag) !== '') {
				$url .= (str_contains($url, '?') ? '&' : '?').$flag.'=1';
			}
		}

		// l.109-116: OpenID Connect sessions go through the OIDC logout url
		if (getDolGlobalInt('MAIN_AUTHENTICATION_OIDC_ON', 0) > 0 && !empty($_SESSION['OPENID_CONNECT']) && getDolGlobalString('MAIN_AUTHENTICATION_OIDC_LOGOUT_URL') !== '') {
			if (str_starts_with($url, '/')) {
				$url = $this->mainUrlRoot.$url;
			}
			$url = getDolGlobalString('MAIN_AUTHENTICATION_OIDC_LOGOUT_URL').'?client_id='.getDolGlobalString('MAIN_AUTHENTICATION_OIDC_CLIENT_ID').'&returnTo='.urlencode($url);
		}

		// l.118-122: destroy the session
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}
		unset($_SESSION['dol_login'], $_SESSION['dol_entity'], $_SESSION['urlfrom']);

		if (self::param($r, 'noredirect') !== '') {
			return new HtmlResponse('');
		}

		return new RedirectResponse($url);
	}

	/**
	 * GETPOST($key): posted value, else query value; '' when absent or '0' like an empty GETPOST.
	 */
	private static function param(Request $r, string $key): string
	{
		$value = $r->post($key) ?? $r->query($key) ?? '';

		return $value === '0' ? '' : $value;
	}
}
