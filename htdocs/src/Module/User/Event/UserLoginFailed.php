<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

/**
 * An authentication attempt was refused.
 */
final class UserLoginFailed
{
	/**
	 * @param string  $username Login that was tried (never the password)
	 * @param int     $entity   Entity the login was tried on
	 * @param ?string $audit    Text for the security events log; null for the bad login/password text of main.inc.php l.~846
	 */
	public function __construct(
		public readonly string $username,
		public readonly int $entity,
		public readonly ?string $audit = null
	) {
	}
}
