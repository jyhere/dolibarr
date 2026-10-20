<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Fixture;

use Dolibarr\Core\Config;

/**
 * A Config whose URL roots are given, the rest read from the wrapped one.
 */
final class UrlRootConfig implements Config
{
	public function __construct(private readonly Config $inner, private readonly string $urlRoot, private readonly string $mainUrlRoot)
	{
	}

	public function string(string $key, string $default = ''): string
	{
		return $this->inner->string($key, $default);
	}

	public function int(string $key, int $default = 0): int
	{
		return $this->inner->int($key, $default);
	}

	public function isModuleEnabled(string $module): bool
	{
		return $this->inner->isModuleEnabled($module);
	}

	public function entity(): int
	{
		return $this->inner->entity();
	}

	public function urlRoot(): string
	{
		return $this->urlRoot;
	}

	public function mainUrlRoot(): string
	{
		return $this->mainUrlRoot;
	}

	public function userString(string $key, string $default = ''): string
	{
		return $this->inner->userString($key, $default);
	}

	public function limitUsers(): int
	{
		return $this->inner->limitUsers();
	}

	public function loginBadCharUnauthorized(): string
	{
		return $this->inner->loginBadCharUnauthorized();
	}
}
