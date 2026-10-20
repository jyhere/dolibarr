<?php

declare(strict_types=1);

namespace Dolibarr\Core;

interface CurrentUser
{
	public function id(): int;

	public function login(): string;

	public function isAdmin(): bool;

	public function companyId(): int;

	public function entity(): int;

	public function hasRight(string $module, string $perm, string $subperm = ''): bool;
}
