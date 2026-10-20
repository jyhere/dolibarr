<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

/**
 * A user has been deleted (USER_DELETE).
 * The legacy trigger has already run inside the legacy method: LegacyTriggerBridge ignores this event.
 */
final class UserDeleted
{
	public function __construct(
		public readonly int $id,
		public readonly string $login,
		public readonly bool $legacyTriggerFired = true
	) {
	}
}
