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
	 *
	 * @param int $method Source order, as GETPOST(): 0 = GET then POST, 1 = GET only, 2 = POST only, 3 = POST then GET
	 */
	public function filter(string $key, string $filter, mixed $default = null, int $method = 0): mixed;

	/**
	 * Whether the parameter is sent, even empty.
	 */
	public function isSet(string $key): bool;

	/**
	 * The extrafields of $element ('user', 'societe'...) posted by a form, keyed 'options_<name>' as
	 * ExtraFields::getOptionalsFromPost() does. Empty when the element has none or none is sent.
	 *
	 * @return array<string, mixed>
	 */
	public function optionals(string $element): array;
}
