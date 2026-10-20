<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

/**
 * Permissions have been granted to or removed from a user (USER_MODIFY).
 * The legacy trigger has already run inside the legacy method: LegacyTriggerBridge ignores this event.
 */
final class UserRightsChanged
{
	public function __construct(
		public readonly int $id,
		public readonly bool $legacyTriggerFired = true
	) {
	}
}
