<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Service;

use Dolibarr\Module\User\Entity\User;

/**
 * What UserService::update() did, for the messages of user/card.php l.665-668 and l.736-750.
 */
final class UserUpdateResult
{
	/**
	 * @param User $user                   The user as stored after the update
	 * @param bool $passwordChanged        A new password was set ($passwordismodified)
	 * @param bool $contactCompanyMismatch The chosen contact has no third party while one was posted (WarningUserDifferentContactSocid)
	 */
	public function __construct(
		public readonly User $user,
		public readonly bool $passwordChanged,
		public readonly bool $contactCompanyMismatch
	) {
	}
}
