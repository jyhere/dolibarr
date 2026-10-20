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

	/**
	 * The legacy object of the element $element (CommonObject::$element), for the generic components.
	 *
	 * @throws \LogicException When the element is not supported yet (only 'user' today)
	 */
	public function byElement(string $element, int $id): ?\CommonObject
	{
		return match ($element) {
			'user' => $this->user($id),
			default => throw new \LogicException('No legacy object loader for the element '.$element),
		};
	}
}
