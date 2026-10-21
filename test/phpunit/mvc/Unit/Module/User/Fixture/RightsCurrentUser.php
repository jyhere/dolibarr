<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User\Fixture;

use Dolibarr\Core\CurrentUser;

/**
 * CurrentUser with configurable rights, given as 'module.perm[.subperm]'.
 * Like \User::hasRight(), legacy names are aliases: lire=read, creer/create=write, supprimer=delete.
 */
final class RightsCurrentUser implements CurrentUser
{
	/**
	 * @param list<string> $rights
	 */
	public function __construct(
		private readonly int $id,
		private readonly bool $admin = false,
		private readonly array $rights = [],
		private readonly int $companyId = 0,
		private readonly int $entity = 1
	) {
	}

	public function id(): int
	{
		return $this->id;
	}

	public function login(): string
	{
		return 'user'.$this->id;
	}

	public function isAdmin(): bool
	{
		return $this->admin;
	}

	public function companyId(): int
	{
		return $this->companyId;
	}

	public function entity(): int
	{
		return $this->entity;
	}

	public function hasRight(string $module, string $perm, string $subperm = ''): bool
	{
		$normalize = static fn (string $permission): string => ['lire' => 'read', 'creer' => 'write', 'create' => 'write', 'supprimer' => 'delete'][$permission] ?? $permission;
		$key = $module.'.'.$normalize($perm).($subperm === '' ? '' : '.'.$normalize($subperm));
		foreach ($this->rights as $right) {
			$parts = array_map($normalize, explode('.', $right));
			if (implode('.', $parts) === $key) {
				return true;
			}
		}

		return false;
	}
}
