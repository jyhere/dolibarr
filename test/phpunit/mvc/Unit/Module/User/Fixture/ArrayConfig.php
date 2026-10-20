<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User\Fixture;

use Dolibarr\Core\Config;

/**
 * Config answering from a fixed map of global constants.
 */
final class ArrayConfig implements Config
{
	/**
	 * @param array<string,string> $constants
	 */
	public function __construct(
		private readonly array $constants = [],
		private readonly int $limitUsers = 0,
		private readonly string $badChars = ',@<>"\''
	) {
	}

	public function string(string $key, string $default = ''): string
	{
		return $this->constants[$key] ?? $default;
	}

	public function int(string $key, int $default = 0): int
	{
		return isset($this->constants[$key]) ? (int) $this->constants[$key] : $default;
	}

	public function isModuleEnabled(string $module): bool
	{
		return false;
	}

	public function entity(): int
	{
		return 1;
	}

	public function urlRoot(): string
	{
		return '';
	}

	public function mainUrlRoot(): string
	{
		return '';
	}

	public function userString(string $key, string $default = ''): string
	{
		return $default;
	}

	public function limitUsers(): int
	{
		return $this->limitUsers;
	}

	public function loginBadCharUnauthorized(): string
	{
		return $this->badChars;
	}
}
