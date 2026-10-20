<?php

declare(strict_types=1);

namespace Dolibarr\Core\Http;

/**
 * Typed reading of a request parameter, with the filters of GETPOST().
 */
interface InputFilter
{
	/**
	 * The value of $key filtered by $filter ('alphanohtml', 'int', 'restricthtml'...), $default when absent.
	 */
	public function filter(string $key, string $filter, mixed $default = null): mixed;

	/**
	 * Whether the parameter is sent, even empty.
	 */
	public function isSet(string $key): bool;
}
