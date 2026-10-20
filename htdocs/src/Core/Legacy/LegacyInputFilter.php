<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\Http\InputFilter;

/**
 * InputFilter backed by GETPOST() and its family: it reads $_GET then $_POST, as left by main.inc.php,
 * with the sanitizing, default values and search persistence of the legacy pages.
 */
final class LegacyInputFilter implements InputFilter
{
	/**
	 * 'int' → GETPOSTINT(), 'float' → GETPOSTFLOAT(), 'date' → GETPOSTDATE($key, '', 'tzuserrel') (fields
	 * $key.'day', $key.'month', $key.'year' of a date selector), any other filter → GETPOST($key, $filter).
	 */
	public function filter(string $key, string $filter, mixed $default = null): mixed
	{
		if ($filter === 'date') {
			if (!$this->isSet($key) && !$this->isSet($key.'day') && !$this->isSet($key.'month') && !$this->isSet($key.'year')) {
				return $default;
			}

			return GETPOSTDATE($key, '', 'tzuserrel');
		}
		if (!$this->isSet($key)) {
			return $default;
		}

		return match ($filter) {
			'int' => GETPOSTINT($key),
			'float' => GETPOSTFLOAT($key),
			default => GETPOST($key, $filter),
		};
	}

	public function isSet(string $key): bool
	{
		return GETPOSTISSET($key);
	}
}
