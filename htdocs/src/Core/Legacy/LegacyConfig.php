<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\Config;

/**
 * Config read through the legacy functions getDolGlobalString(), getDolGlobalInt(), isModEnabled(),
 * getDolUserString() and the DOL_*URL_ROOT constants.
 */
final class LegacyConfig implements Config
{
	public function __construct(private readonly LegacyContext $ctx)
	{
	}

	public function string(string $key, string $default = ''): string
	{
		return getDolGlobalString($key, $default);
	}

	public function int(string $key, int $default = 0): int
	{
		return getDolGlobalInt($key, $default);
	}

	public function isModuleEnabled(string $module): bool
	{
		return isModEnabled($module);
	}

	public function entity(): int
	{
		return (int) $this->ctx->conf->entity;
	}

	public function urlRoot(): string
	{
		return DOL_URL_ROOT;
	}

	public function mainUrlRoot(): string
	{
		return DOL_MAIN_URL_ROOT;
	}

	/**
	 * getDolUserString() on the user of the context, $default without user.
	 */
	public function userString(string $key, string $default = ''): string
	{
		$user = $this->ctx->user;

		return $user === null ? $default : getDolUserString($key, $default, $user);
	}

	public function limitUsers(): int
	{
		return (int) ($this->ctx->conf->file->main_limit_users ?? 0);
	}

	public function loginBadCharUnauthorized(): string
	{
		return getDolGlobalLoginBadCharUnauthorized();
	}
}
