<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Event;

/**
 * The session of a user is being closed (htdocs/user/logout.php).
 */
final class UserLoggedOut
{
	public function __construct(public readonly int $userId, public readonly string $login)
	{
	}
}
