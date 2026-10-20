<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\CurrentUser;

final class LegacyCurrentUser implements CurrentUser
{
	public function __construct(private readonly \User $user)
	{
	}

	public function id(): int
	{
		return (int) $this->user->id;
	}

	public function login(): string
	{
		return (string) $this->user->login;
	}

	public function isAdmin(): bool
	{
		return !empty($this->user->admin);
	}

	public function companyId(): int
	{
		return (int) $this->user->socid;
	}

	public function entity(): int
	{
		return (int) $this->user->entity;
	}

	public function hasRight(string $module, string $perm, string $subperm = ''): bool
	{
		return (bool) $this->user->hasRight($module, $perm, $subperm);
	}

	public function legacy(): \User
	{
		return $this->user;
	}
}
