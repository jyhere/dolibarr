<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

use Dolibarr\Module\User\Entity\User;

/**
 * A user card has been modified (USER_MODIFY).
 * The legacy trigger has already run inside the legacy method: LegacyTriggerBridge ignores this event.
 */
final class UserUpdated
{
	public function __construct(
		public readonly User $before,
		public readonly User $after,
		public readonly bool $legacyTriggerFired = true
	) {
	}
}
