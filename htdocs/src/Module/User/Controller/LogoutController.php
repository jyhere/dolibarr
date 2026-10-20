<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Controller;

use Dolibarr\Core\Config;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Http\HtmlResponse;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\Response;
use Dolibarr\Core\Http\SafeRedirect;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyInput;
use Dolibarr\Core\Routing\Route;
use Dolibarr\Core\Session;
use Dolibarr\Module\User\Event\UserLoggedOut;

/**
 * Disconnection of the user, htdocs/user/logout.php.
 * urlfrom (session or request value) gets the GETPOST() filter of logout.php, then must be a path of this
 * instance (SafeRedirect), else the default target is used. MAIN_LOGOUT_GOTO_URL is setup: trusted, as in logout.php.
 */
final class LogoutController
{
	/** Display flags kept across the disconnection (logout.php l.88-107). */
	private const MENU_FLAGS = ['dol_hide_topmenu', 'dol_hide_leftmenu', 'dol_optimize_smallscreen', 'dol_no_mouse_hover', 'dol_use_jmobile'];

	/**
	 * @param ?CurrentUser $me   The logged user, carried by the UserLoggedOut event
	 * @param ?\User       $user The global $user: afterLogout hooks object
	 */
	public function __construct(
		private readonly Session $session,
		private readonly Config $config,
		private readonly EventDispatcher $dispatcher,
		private readonly LegacyHooks $hooks,
		private readonly ?CurrentUser $me,
		private readonly ?\User $user
	) {
	}

	/**
	 * GET stays for the legacy menu link, POST is the preferred method.
	 */
	#[Route('/logout', methods: ['GET', 'POST'], name: 'logout')]
	public function logout(Request $r): Response
	{
		$urlRoot = $this->config->urlRoot();

		// A present but invalid CSRF token: main.inc.php has emptied $_POST, set a warning message and
		// errorcode=InvalidToken. The disconnection is cancelled (spec §8; legacy logout.php disconnects anyway).
		// A GET without token, the legacy menu link, still disconnects.
		if ($r->get('errorcode', 'aZ09') === 'InvalidToken') {
			return new RedirectResponse($urlRoot.'/index.php');
		}

		// l.50-56: forced authentication modes, only the session keys are cleared
		$authMode = $this->session->get('dol_authmode', '');
		if ($authMode === 'forceuser' || $authMode === 'http') {
			$this->clearLogin();

			return new HtmlResponse('');
		}

		// l.68-73: trigger USER_LOGOUT (through the LegacyTriggerBridge)
		if ($this->me !== null) {
			$this->dispatcher->dispatch(new UserLoggedOut($this->me->id(), $this->me->login()));
		}
		$user = $this->user;

		// l.75-82: hooks on logout
		$action = '';
		$object = $user;
		$this->hooks->init(['logout']);
		$this->hooks->execute('afterLogout', [], $object, $action);

		// l.84-100: url to go after the disconnection. GETPOST('urlfrom') applies its URL cleaning to the
		// request value; the session value gets the same one through LegacyInput::urlParameter().
		$sessionUrlfrom = $this->session->get('urlfrom', '');
		$urlfrom = is_string($sessionUrlfrom) && $sessionUrlfrom !== '' ? LegacyInput::urlParameter($sessionUrlfrom) : self::text($r->get('urlfrom'));
		$url = $urlRoot.'/index.php';
		if ($urlfrom !== '') {
			$url = SafeRedirect::relativePath($urlRoot.$urlfrom, $urlRoot) ?? $url;
		}
		$gotoUrl = $this->config->string('MAIN_LOGOUT_GOTO_URL');
		if ($gotoUrl !== '') {
			$url = $gotoUrl;
		}
		foreach (self::MENU_FLAGS as $flag) {
			if (!empty($r->get($flag))) {
				$url .= (str_contains($url, '?') ? '&' : '?').$flag.'=1';
			}
		}

		// l.109-116: OpenID Connect sessions go through the OIDC logout url
		$oidcLogoutUrl = $this->config->string('MAIN_AUTHENTICATION_OIDC_LOGOUT_URL');
		if ($this->config->int('MAIN_AUTHENTICATION_OIDC_ON') > 0 && !empty($this->session->get('OPENID_CONNECT')) && $oidcLogoutUrl !== '') {
			if (str_starts_with($url, '/')) {
				$url = $this->config->mainUrlRoot().$url;
			}
			$url = $oidcLogoutUrl.'?client_id='.$this->config->string('MAIN_AUTHENTICATION_OIDC_CLIENT_ID').'&returnTo='.urlencode($url);
		}

		// l.118-122: destroy the session
		$this->session->destroy();

		if (!empty($r->get('noredirect'))) {
			return new HtmlResponse('');
		}

		return new RedirectResponse($url);
	}

	private function clearLogin(): void
	{
		foreach (['dol_login', 'dol_entity', 'urlfrom'] as $key) {
			$this->session->remove($key);
		}
	}

	/**
	 * A filtered value as a string: '' when absent or not a string.
	 */
	private static function text(mixed $value): string
	{
		return is_string($value) ? $value : '';
	}
}
