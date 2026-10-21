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
	 * @param list<string>         $modules   The modules enabled
	 */
	public function __construct(
		private readonly array $constants = [],
		private readonly int $limitUsers = 0,
		private readonly string $badChars = ',@<>"\'',
		private readonly array $modules = []
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
		return in_array($module, $this->modules, true);
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
