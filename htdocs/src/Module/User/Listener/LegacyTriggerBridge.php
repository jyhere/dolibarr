<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Listener;

use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Logger;
use Dolibarr\Module\User\Event\UserLoggedIn;
use Dolibarr\Module\User\Event\UserLoggedOut;
use Dolibarr\Module\User\Event\UserLoginFailed;
use Dolibarr\Module\User\Security\AuthenticationException;

/**
 * Runs the legacy triggers (security events log, modules) for the authentication events.
 * The events of UserService (UserCreated, UserUpdated...) are not subscribed: their legacy
 * triggers already ran inside the legacy methods (legacyTriggerFired).
 */
final class LegacyTriggerBridge
{
	public function __construct(
		private readonly LegacyTriggers $triggers,
		private readonly LegacyContext $ctx,
		private readonly LegacyObjectLoader $loader,
		private readonly Logger $log
	) {
	}

	public function register(EventDispatcher $d): void
	{
		$d->addListener(UserLoggedIn::class, $this->onLoggedIn(...));
		$d->addListener(UserLoginFailed::class, $this->onLoginFailed(...));
		$d->addListener(UserLoggedOut::class, $this->onLoggedOut(...));
	}

	/**
	 * USER_LOGIN, main.inc.php l.1159-1164.
	 *
	 * @throws AuthenticationException When a trigger fails, so that the session opening is rolled back
	 */
	public function onLoggedIn(UserLoggedIn $e): void
	{
		$user = $this->loader->user($e->userId);
		if ($user === null) {
			$this->log->warning('LegacyTriggerBridge: user '.$e->userId.' not found, USER_LOGIN trigger skipped');

			return;
		}
		$user->context['audit'] = $e->loginInfo;
		$user->context['authentication_method'] = $e->authMode;
		if ($this->triggers->run('USER_LOGIN', $user, $user) < 0) {
			throw new AuthenticationException('Error in some triggers USER_LOGIN: '.implode(', ', $this->triggers->errors()));
		}
	}

	/**
	 * USER_LOGIN_FAILED on an empty user, as main.inc.php l.845-853 does with the not yet logged $user.
	 */
	public function onLoginFailed(UserLoginFailed $e): void
	{
		$user = new \User($this->ctx->db);
		$user->login = $e->username;
		if ($e->audit !== null) {
			$user->context['audit'] = $e->audit;
		} else {
			$this->ctx->langs->loadLangs(['main', 'errors']);
			$user->context['audit'] = $this->ctx->langs->trans('ErrorBadLoginPassword').' - login='.$e->username;
		}
		$this->triggers->run('USER_LOGIN_FAILED', $user, $user);
	}

	/**
	 * USER_LOGOUT, htdocs/user/logout.php.
	 */
	public function onLoggedOut(UserLoggedOut $e): void
	{
		$user = $this->loader->user($e->userId);
		if ($user === null) {
			$this->log->warning('LegacyTriggerBridge: user '.$e->userId.' not found, USER_LOGOUT trigger skipped');

			return;
		}
		$this->triggers->run('USER_LOGOUT', $user, $user);
	}
}
