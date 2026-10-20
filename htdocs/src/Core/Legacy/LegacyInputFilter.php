<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\Http\InputFilter;

/**
 * InputFilter backed by GETPOST() and its family, on $_GET and $_POST as left by main.inc.php.
 * A key that GETPOSTISSET() reports absent returns $default without calling GETPOST(): the setup default
 * values (MAIN_ENABLE_DEFAULT_VALUES, $user->default_values) never apply. A sent key gets everything
 * GETPOST() does: sanitizing, URL cleaning of backto* and urlfrom*, search persistence.
 * A form that needs the GETPOST() default values must call GETPOST() itself; this filter cannot give them.
 */
final class LegacyInputFilter implements InputFilter
{
	/**
	 * 'int' → GETPOSTINT(), 'float' → GETPOSTFLOAT(), 'date' → GETPOSTDATE($key, '', 'tzuserrel') (fields
	 * $key.'day', $key.'month', $key.'year' of a date selector), any other filter → GETPOST($key, $filter).
	 * With a $method other than 0, the same reads from that source order: 'int' → (int) GETPOST($key, 'int', $method),
	 * 'float' → GETPOSTFLOAT()'s price2num() on GETPOST($key, 'alphanohtml', $method), others → GETPOST($key, $filter, $method).
	 * 'date' ignores $method: GETPOSTDATE() reads GET then POST.
	 */
	public function filter(string $key, string $filter, mixed $default = null, int $method = 0): mixed
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

		if ($method === 0) {
			return match ($filter) {
				'int' => GETPOSTINT($key),
				'float' => GETPOSTFLOAT($key),
				default => GETPOST($key, $filter),
			};
		}
		if ($method < 1 || $method > 3) {
			throw new \InvalidArgumentException('GETPOST method must be 0 to 3, '.$method.' given');
		}

		return match ($filter) {
			'int' => (int) GETPOST($key, 'int', $method),
			'float' => (float) price2num(GETPOST($key, 'alphanohtml', $method), '', 2),
			default => GETPOST($key, $filter, $method),
		};
	}

	public function isSet(string $key): bool
	{
		return GETPOSTISSET($key);
	}

	/**
	 * Loads the extrafield definitions of $element and has ExtraFields::setOptionalsFromPost() fill a
	 * stub object, as card.php does (null on a creation, '@GETPOSTISSET' on an update). A required field
	 * left empty gives the message of the extrafields under OPTIONALS_ERROR.
	 */
	public function optionals(string $element, bool $update = false): array
	{
		global $db;

		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
		$extrafields = new \ExtraFields($db);
		$extrafields->fetch_name_optionals_label($element);

		$stub = new class ($db, $element) extends \CommonObject {
			public function __construct(\DoliDB $db, string $element)
			{
				$this->db = $db;
				$this->table_element = $element;
			}
		};
		$stub->array_options = [];

		$ret = $extrafields->setOptionalsFromPost(null, $stub, $update ? '@GETPOSTISSET' : '');

		/** @var array<string, mixed> $result */
		$result = $stub->array_options;
		if ($ret < 0) {
			$result[self::OPTIONALS_ERROR] = $extrafields->error;
		}

		return $result;
	}
}
