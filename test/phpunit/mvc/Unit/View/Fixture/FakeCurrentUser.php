<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\View\Fixture;

use Dolibarr\Core\CurrentUser;

final class FakeCurrentUser implements CurrentUser
{
	public function id(): int
	{
		return 1;
	}

	public function login(): string
	{
		return 'admin';
	}

	public function isAdmin(): bool
	{
		return true;
	}

	public function companyId(): int
	{
		return 0;
	}

	public function entity(): int
	{
		return 1;
	}

	public function hasRight(string $module, string $perm, string $subperm = ''): bool
	{
		return $module === 'user' && $perm === 'read';
	}
}
