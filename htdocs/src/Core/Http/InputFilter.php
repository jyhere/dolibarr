<?php

declare(strict_types=1);

namespace Dolibarr\Core\Http;

/**
 * Typed reading of a request parameter, with the filters of GETPOST().
 */
interface InputFilter
{
	/** Key of the optionals() result carrying the error message of a required extrafield left empty */
	public const OPTIONALS_ERROR = '@error';

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
	 * The extrafields of $element ('user', 'societe'...) posted by a form, keyed 'options_<name>', as
	 * ExtraFields::setOptionalsFromPost() sets them: disabled, hidden or non-editable fields are left out,
	 * and with $update ('@GETPOSTISSET') so is any field the form does not carry. When a required field is
	 * empty the array holds the message under the key OPTIONALS_ERROR.
	 *
	 * @return array<string, mixed>
	 */
	public function optionals(string $element, bool $update = false): array;
}
