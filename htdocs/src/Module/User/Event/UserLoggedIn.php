<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

/**
 * A new authenticated session has been opened (main.inc.php l.1100-1192).
 */
final class UserLoggedIn
{
	/**
	 * @param \User  $user      Legacy user that logged in, its context['audit'] and ['authentication_method'] already set
	 * @param string $authMode  Authentication mode that validated the credentials (dolibarr, ldap...)
	 * @param string $loginInfo The $loginfo string of main.inc.php l.1151-1152
	 */
	public function __construct(
		public readonly \User $user,
		public readonly string $authMode,
		public readonly string $loginInfo
	) {
	}
}
