<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Listener;

use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Module\User\Event\UserLoggedIn;
use Dolibarr\Module\User\Event\UserLoggedOut;
use Dolibarr\Module\User\Event\UserLoginFailed;
use Dolibarr\Module\User\Security\AuthenticationException;

/**
 * Runs the legacy triggers (security events log, modules) for the authentication events.
 */
final class LegacyTriggerBridge
{
	public function __construct(private readonly LegacyTriggers $triggers, private readonly LegacyContext $ctx)
	{
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
		if ($this->triggers->run('USER_LOGIN', $e->user, $e->user) < 0) {
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
		$this->triggers->run('USER_LOGOUT', $e->user, $e->user);
	}
}
