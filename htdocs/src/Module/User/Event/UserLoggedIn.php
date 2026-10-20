<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

/**
 * A new authenticated session has been opened (main.inc.php l.1100-1192).
 */
final class UserLoggedIn
{
	/**
	 * @param int    $userId    Id of the user that logged in
	 * @param string $login     His login
	 * @param string $authMode  Authentication mode that validated the credentials (dolibarr, ldap...)
	 * @param string $loginInfo The $loginfo string of main.inc.php l.1151-1152
	 */
	public function __construct(
		public readonly int $userId,
		public readonly string $login,
		public readonly string $authMode,
		public readonly string $loginInfo
	) {
	}
}
