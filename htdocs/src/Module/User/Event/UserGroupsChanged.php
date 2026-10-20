<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

/**
 * A user has been added to or removed from a group (USER_MODIFY).
 * The legacy trigger has already run inside the legacy method: LegacyTriggerBridge ignores this event.
 */
final class UserGroupsChanged
{
	public function __construct(
		public readonly int $id,
		public readonly bool $legacyTriggerFired = true
	) {
	}
}
