<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Security;

use Dolibarr\Core\Legacy\LegacyContext;

/**
 * Credentials check of the login form, through the legacy functions of
 * core/lib/security2.lib.php, as main.inc.php l.769-809 does.
 */
final class Authenticator
{
	/** OAuth modes main.inc.php l.773 removes unless their button was clicked: never on the login form post. */
	private const OAUTH_MODES = ['googleoauth'];

	/** @var list<string> */
	private readonly array $authModes;

	/**
	 * @param list<string> $authModes $dolibarr_main_authentication exploded on ','
	 */
	public function __construct(private readonly LegacyContext $ctx, array $authModes)
	{
		$modes = [];
		foreach ($authModes as $mode) {
			$mode = trim($mode);
			if ($mode !== '') {
				$modes[] = $mode;
			}
		}
		$this->authModes = $modes;
	}

	/**
	 * First step: login and password (main.inc.php l.797-808).
	 *
	 * @param int $entity Entity to log in, 0 for the current one
	 */
	public function attempt(string $username, string $password, int $entity): AuthenticationResult
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

		$entity = $this->entity($entity);
		unset($_SESSION['dol_totp_pending']);	// A new password step cancels any pending second step (l.797)

		$modes = array_values(array_diff($this->authModes, self::OAUTH_MODES));
		// main.inc.php l.870: some LDAP servers accept an anonymous bind, an empty password is always refused with ldap
		if ($modes === [] || $username === '' || ($password === '' && in_array('ldap', $modes, true) && !in_array('openid_connect', $modes, true))) {
			return AuthenticationResult::failure();
		}

		$login = checkLoginPassEntity($username, $password, $entity, $modes);
		if (!is_string($login) || $login === '' || $login === '--bad-login-validity--') {
			return AuthenticationResult::failure();
		}
		$authMode = $this->validatedAuthMode();

		if (in_array($authMode, ['dolibarr', 'ldap'], true)) {
			// The password is right, but a user with a two-factor authentication needs a code before any session is opened (l.802-806)
			$login = dolTotpStartSecondFactor($this->ctx->db, $login, $entity);
			if ($login === '') {
				return AuthenticationResult::totpRequired($username);
			}
		}

		return AuthenticationResult::success($login, $authMode, $entity);
	}

	/**
	 * Second step of a two-factor authentication (main.inc.php l.789-794). The login and the
	 * entity come from the session, never from the request; dolTotpCheckPendingLogin() reads
	 * the code from $_POST['totpcode'] itself, so that a code never travels in a URL.
	 *
	 * @param string $code The posted code; only its presence is checked here
	 */
	public function attemptTotp(string $code): AuthenticationResult
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

		$pending = $_SESSION['dol_totp_pending'] ?? null;
		if (!is_array($pending) || empty($pending['login'])) {
			return AuthenticationResult::failure();
		}
		$entity = (int) ($pending['entity'] ?? 0);

		$login = dolTotpCheckPendingLogin($this->ctx->db);
		if (!is_string($login) || $login === '') {
			return AuthenticationResult::failure();
		}

		return AuthenticationResult::success($login, $this->validatedAuthMode(), $entity);
	}

	/**
	 * Whether the login form must carry a valid captcha code (main.inc.php l.621).
	 */
	public function captchaRequired(string $username): bool
	{
		return $username !== '' && getDolGlobalString('MAIN_SECURITY_ENABLECAPTCHA') !== '' && !isset($_SESSION['dol_bypass_antispam']);
	}

	/**
	 * Validate the captcha of the login form with its handler (main.inc.php l.621-667).
	 * On a refusal, the message is stored in $_SESSION['dol_loginmesg'].
	 */
	public function checkCaptcha(): bool
	{
		$captcha = getDolGlobalString('MAIN_SECURITY_ENABLECAPTCHA_HANDLER', 'standard');

		$dirs = ['main' => '/core/modules/security/captcha/'];
		$parts = $this->ctx->conf->modules_parts['captcha'] ?? [];
		if (is_array($parts)) {
			$dirs = array_merge($dirs, $parts);
		}
		$classFile = '';
		foreach ($dirs as $dir) {
			$classFile = dol_buildpath((is_string($dir) ? $dir : '').'modCaptcha'.ucfirst($captcha).'.class.php', 0, 2);
			if ($classFile !== '') {
				break;
			}
		}

		$ok = false;
		$className = 'modCaptcha'.ucfirst($captcha);
		if ($classFile === '') {
			$_SESSION['dol_loginmesg'] = 'Error, the captcha handler '.$captcha.' has no class file found '.$className;
		} else {
			include_once $classFile;
			if (!class_exists($className)) {
				$_SESSION['dol_loginmesg'] = 'Error, the captcha handler class '.$className.' was not found after the include';
			} else {
				$handler = new $className($this->ctx->db, $this->ctx->conf, $this->ctx->langs, $this->ctx->user);
				if (method_exists($handler, 'validateCodeAfterLoginSubmit')) {
					$ok = (bool) $handler->validateCodeAfterLoginSubmit();
				} else {
					$_SESSION['dol_loginmesg'] = 'Error, the captcha handler '.get_class($handler).' does not have any method validateCodeAfterLoginSubmit()';
				}
			}
		}

		if (!$ok) {
			$this->ctx->langs->loadLangs(['main', 'errors']);
			$previous = $_SESSION['dol_loginmesg'] ?? '';
			$_SESSION['dol_loginmesg'] = (empty($previous) || !is_string($previous) ? '' : $previous."<br>\n").$this->ctx->langs->transnoentitiesnoconv('ErrorBadValueForCode');
		}

		return $ok;
	}

	/**
	 * The entity to test (main.inc.php l.717): the posted one, else the current one, else 1.
	 */
	private function entity(int $entity): int
	{
		if ($entity > 0) {
			return $entity;
		}
		$current = (int) $this->ctx->conf->entity;

		return $current > 0 ? $current : 1;
	}

	/**
	 * Mode that validated the credentials, set by checkLoginPassEntity() or dolTotpCheckPendingLogin().
	 */
	private function validatedAuthMode(): string
	{
		$conf = $this->ctx->conf;
		$mode = property_exists($conf, 'authmode') ? $conf->authmode : '';

		return is_string($mode) ? $mode : '';
	}
}
