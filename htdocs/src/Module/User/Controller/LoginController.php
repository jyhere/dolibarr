<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Controller;

use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Http\HtmlResponse;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\Response;
use Dolibarr\Core\Http\SafeRedirect;
use Dolibarr\Core\Legacy\HookResult;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Logger;
use Dolibarr\Core\Routing\Route;
use Dolibarr\Module\User\Event\UserLoginFailed;
use Dolibarr\Module\User\Security\AuthenticationException;
use Dolibarr\Module\User\Security\AuthenticationResult;
use Dolibarr\Module\User\Security\Authenticator;
use Dolibarr\Module\User\Security\SessionAuthenticator;
use Dolibarr\Module\User\View\LegacyLoginPage;

/**
 * Login form and its submission, the login branch of main.inc.php l.537-1192.
 * The route is public: main.inc.php has started the session and checked the CSRF token.
 */
final class LoginController
{
	/** Posted fields stored into the session by SessionAuthenticator (main.inc.php l.574-583, l.812-830, l.1120). */
	private const CLIENT_INFO_FIELDS = [
		'tz', 'tz_string', 'dst_observed', 'dst_first', 'dst_second', 'screenwidth', 'screenheight',
		'dol_hide_topmenu', 'dol_hide_leftmenu', 'dol_optimize_smallscreen', 'dol_no_mouse_hover', 'dol_use_jmobile', 'dol_openinpopup',
	];

	public function __construct(
		private readonly LegacyContext $ctx,
		private readonly Authenticator $authenticator,
		private readonly SessionAuthenticator $session,
		private readonly EventDispatcher $dispatcher,
		private readonly LegacyHooks $hooks,
		private readonly LegacyLoginPage $page,
		private readonly Logger $log,
		private readonly string $urlRoot
	) {
	}

	/**
	 * The legacy login page (dol_loginfunction()), or the home page when already logged in.
	 */
	#[Route('/login', methods: ['GET'], name: 'login', public: true)]
	public function form(Request $r): Response
	{
		if (!empty($_SESSION['dol_login'])) {
			return new RedirectResponse($this->urlRoot.'/index.php');
		}

		return new HtmlResponse($this->page->render());
	}

	#[Route('/login', methods: ['POST'], name: 'login_post', public: true)]
	public function authenticate(Request $r): Response
	{
		// main.inc.php l.569: an already authenticated session ignores the login fields
		if (!empty($_SESSION['dol_login'])) {
			return new RedirectResponse($this->urlRoot.'/index.php');
		}

		$langs = $this->ctx->langs;
		$langs->loadLangs(['main', 'errors']);

		$username = sanitizeVal(self::posted($r, 'username'), 'alpha');	// GETPOST('username', 'alpha'), main.inc.php l.707
		$username = is_string($username) ? $username : '';
		$entity = (int) $r->post('entity', '0');
		$backToPage = SafeRedirect::relativePath($r->post('backtopage') ?? $r->query('backtopage'), $this->urlRoot);

		// main.inc.php l.610-617: hooks for security access
		$this->hooks->init(['login']);
		if ($this->executeHook('beforeLoginAuthentication', [])->failed()) {
			return $this->toLoginPage($backToPage);
		}

		// l.621-700: captcha
		if ($r->post('actionlogin') === 'login' && $this->authenticator->captchaRequired($username) && !$this->authenticator->checkCaptcha()) {
			$this->log->warning('--- Security warning: Bad value for code, connection refused');

			return $this->failed($username, $entity, 'ErrorBadValueForCode - login='.$username, $backToPage);
		}

		// l.759-764: a password in the query string ends up in access logs, history and Referer headers
		if ((string) $r->query('password', '') !== '' && (string) $r->query('afteroauthloginreturn', '') === '') {
			$this->log->warning('--- Login submission with credentials in the query string refused for '.$this->urlRoot.'/login');
			$_SESSION['dol_loginmesg'] = $langs->transnoentitiesnoconv('ErrorLoginMustBePostMethod');

			return $this->toLoginPage($backToPage);
		}

		if ($r->post('actionlogin') !== 'login') {
			return $this->toLoginPage($backToPage);
		}

		// l.787-808: second step of a two-factor authentication, or login and password
		$secondStep = !empty($_SESSION['dol_totp_pending']) && $r->post('totpcode') !== null;
		$result = $secondStep
			? $this->authenticator->attemptTotp((string) $r->post('totpcode'))
			: $this->authenticator->attempt($username, self::posted($r, 'password'), $entity);
		if ($result->needsTotp()) {
			return $this->toLoginPage($backToPage);
		}
		if (!$result->ok()) {
			$this->log->info('Bad password, connection refused (see a previous notice message for more info)');
			$audit = $secondStep ? $langs->trans('TOTPCodeRefused').' - login='.$username : null;

			return $this->failed($username, $entity, $audit, $backToPage);
		}

		return $this->openSession($r, $result, $backToPage);
	}

	/**
	 * main.inc.php l.880-958 then l.1098-1192: load the user, open the session, go to the next page.
	 */
	private function openSession(Request $r, AuthenticationResult $result, ?string $backToPage): Response
	{
		$langs = $this->ctx->langs;
		$login = $result->login();
		$entity = $result->entity();

		$user = new \User($this->ctx->db);
		$fetched = $user->fetch(0, $login, '', 1, $entity > 0 ? $entity : -1);
		if ($fetched <= 0 || $user->isNotIntoValidityDateRange()) {
			$this->log->info('User not found or not valid, connection refused');
			if ($fetched == 0) {
				$_SESSION['dol_loginmesg'] = $langs->transnoentitiesnoconv('ErrorCantLoadUserFromDolibarrDatabase', $login);
				$audit = 'ErrorCantLoadUserFromDolibarrDatabase - login='.$login;
			} elseif ($fetched < 0) {
				$_SESSION['dol_loginmesg'] = (string) $user->error;
				$audit = (string) $user->error;
			} else {
				$_SESSION['dol_loginmesg'] = $langs->transnoentitiesnoconv('ErrorLoginDateValidity');
				$audit = $langs->trans('ErrorLoginDateValidity').' - login='.$login;
			}

			return $this->failed($login, $entity, $audit, $backToPage, $result->authMode());
		}

		// l.954-957: the login was forced to another entity than the current one, and succeeded
		if ($entity > 0 && (int) $this->ctx->conf->entity !== $entity) {
			$this->ctx->conf->entity = $entity;
		}

		$clientInfo = [];
		foreach (self::CLIENT_INFO_FIELDS as $field) {
			$value = $r->post($field);
			if ($value !== null) {
				$clientInfo[$field] = $value;
			}
		}

		try {
			$this->session->login($user, $result->authMode(), $clientInfo);
		} catch (AuthenticationException) {
			return new HtmlResponse(dol_escape_htmltag('Error in some triggers USER_LOGIN or in some hooks afterLogin'), 500);
		}

		// l.1195-1211: a user who must change his password can only reach the change password page (or log out).
		// main.inc.php checks it on the login request only, so the next page must be that one. Legacy runs the
		// MAIN_LANDING_PAGE redirection first, which skips this check: here the forced change always wins.
		if (!empty($user->force_pass_change) && $result->authMode() === 'dolibarr') {
			return new RedirectResponse($this->urlRoot.'/user/changepassword.php');
		}

		return new RedirectResponse($backToPage ?? $this->landingPage($user) ?? $this->urlRoot.'/index.php');
	}

	/**
	 * l.1180-1192: the landing page of the user, else of the instance.
	 */
	private function landingPage(\User $user): ?string
	{
		$landingPage = getDolUserString('MAIN_LANDING_PAGE', getDolGlobalString('MAIN_LANDING_PAGE'), $user);
		if ($landingPage === '') {
			return null;
		}

		return SafeRedirect::relativePath(dol_buildpath($landingPage, 1), $this->urlRoot);
	}

	/**
	 * A refused authentication (l.836-863): message, USER_LOGIN_FAILED trigger, afterLoginFailed hooks.
	 */
	private function failed(string $username, int $entity, ?string $audit, ?string $backToPage, string $authMode = ''): Response
	{
		if (empty($_SESSION['dol_loginmesg'])) {
			$_SESSION['dol_loginmesg'] = $this->ctx->langs->transnoentitiesnoconv('ErrorBadLoginPassword');
		}

		$this->dispatcher->dispatch(new UserLoginFailed($username, $entity, $audit));

		$this->hooks->init(['login']);
		$this->executeHook('afterLoginFailed', ['dol_authmode' => $authMode, 'dol_loginmesg' => $_SESSION['dol_loginmesg']]);

		return $this->toLoginPage($backToPage);
	}

	/**
	 * @param array<string,mixed> $parameters
	 */
	private function executeHook(string $name, array $parameters): HookResult
	{
		$action = '';
		$object = $this->ctx->user;

		return $this->hooks->execute($name, $parameters, $object, $action);
	}

	private function toLoginPage(?string $backToPage): RedirectResponse
	{
		return new RedirectResponse($this->urlRoot.'/login'.($backToPage === null ? '' : '?backtopage='.rawurlencode($backToPage)));
	}

	private static function posted(Request $r, string $key): string
	{
		return $r->post($key) ?? '';
	}
}
