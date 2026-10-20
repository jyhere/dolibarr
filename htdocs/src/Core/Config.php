<?php

declare(strict_types=1);

namespace Dolibarr\Core;

/**
 * Setup of the instance: global constants, enabled modules, entity, URL roots.
 */
interface Config
{
	/**
	 * A global constant ($conf->global), $default when not set.
	 */
	public function string(string $key, string $default = ''): string;

	public function int(string $key, int $default = 0): int;

	public function isModuleEnabled(string $module): bool;

	/**
	 * The current entity.
	 */
	public function entity(): int;

	/**
	 * DOL_URL_ROOT, the URL path of the instance ('' at the root of the host).
	 */
	public function urlRoot(): string;

	/**
	 * DOL_MAIN_URL_ROOT, the absolute URL of the instance.
	 */
	public function mainUrlRoot(): string;

	/**
	 * A personal constant of the current user ($user->conf), $default when not set or without user.
	 */
	public function userString(string $key, string $default = ''): string;
}
