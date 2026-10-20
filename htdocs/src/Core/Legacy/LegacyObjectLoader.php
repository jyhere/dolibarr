<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\Database\Connection;

/**
 * Loads legacy objects, memoized per request.
 */
final class LegacyObjectLoader
{
	/** @var array<int,?\User> */
	private array $users = [];

	public function __construct(private readonly Connection $connection)
	{
	}

	public function user(int $id): ?\User
	{
		if (array_key_exists($id, $this->users)) {
			return $this->users[$id];
		}

		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		$user = new \User($this->connection->legacy());

		return $this->users[$id] = $user->fetch($id) > 0 ? $user : null;
	}
}
