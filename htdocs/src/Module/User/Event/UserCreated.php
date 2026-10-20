<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

use Dolibarr\Module\User\Entity\User;

/**
 * A user has been created (USER_CREATE).
 * The legacy trigger has already run inside the legacy method: LegacyTriggerBridge ignores this event.
 */
final class UserCreated
{
	public function __construct(
		public readonly User $user,
		public readonly bool $legacyTriggerFired = true
	) {
	}
}
