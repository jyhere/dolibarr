<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Security;

/**
 * Outcome of a credentials check: success, failure or waiting for the second factor.
 */
final class AuthenticationResult
{
	private function __construct(
		private readonly bool $ok,
		private readonly bool $needsTotp,
		private readonly string $login,
		private readonly string $authMode,
		private readonly int $entity
	) {
	}

	/**
	 * @param int $entity Entity the credentials were validated on
	 */
	public static function success(string $login, string $authMode, int $entity = 0): self
	{
		return new self(true, false, $login, $authMode, $entity);
	}

	public static function failure(): self
	{
		return new self(false, false, '', '', 0);
	}

	public static function totpRequired(string $login): self
	{
		return new self(false, true, $login, '', 0);
	}

	public function ok(): bool
	{
		return $this->ok;
	}

	public function login(): string
	{
		return $this->login;
	}

	public function authMode(): string
	{
		return $this->authMode;
	}

	public function entity(): int
	{
		return $this->entity;
	}

	public function needsTotp(): bool
	{
		return $this->needsTotp;
	}
}
