<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

/**
 * The password of a user has been changed (USER_NEW_PASSWORD), and possibly sent by email.
 * The legacy trigger has already run inside the legacy method: LegacyTriggerBridge ignores this event.
 */
final class UserPasswordChanged
{
	public function __construct(
		public readonly int $id,
		public readonly bool $sent,
		public readonly bool $legacyTriggerFired = true
	) {
	}
}
