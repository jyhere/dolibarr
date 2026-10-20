<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

use Dolibarr\Module\User\Entity\User;

/**
 * A user has been enabled or disabled (USER_ENABLEDISABLE).
 * The legacy trigger has already run inside the legacy method: LegacyTriggerBridge ignores this event.
 */
final class UserStatusChanged
{
	public function __construct(
		public readonly User $user,
		public readonly bool $enabled,
		public readonly bool $legacyTriggerFired = true
	) {
	}
}
