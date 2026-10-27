<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * Turns the SQL of the MVC core into a BoundStatement:
 * - '@table' becomes the prefixed table name ('@user' → 'llx_user');
 * - ':name' becomes '?', its value appended to the bound values (a name may appear several times);
 * - an array becomes '?, ?, …', an empty array 'NULL' (so 'IN (:ids)' is a false condition, not invalid SQL).
 * String literals ('…', "…") and '::' (pgsql casts) are left untouched; a '?' outside them is refused, the
 * drivers owning that placeholder.
 *
 * Values: int, float, string, null as is; bool as 0/1; a backed enum as its value; a DateTimeInterface as
 * 'Y-m-d H:i:s', without time zone conversion (spec §5.6).
 */
final class ParameterBinder
{
	private const TOKENS = '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|::|:([a-zA-Z_][a-zA-Z0-9_]*)|@([a-z0-9_]+)|\\?/s';

	public function __construct(private readonly string $prefix)
	{
	}

	/**
	 * @param array<mixed> $params Values by parameter name, without the colon
	 * @throws \InvalidArgumentException Missing, extra or unsupported parameter (named in the message)
	 */
	public function bind(string $sql, array $params): BoundStatement
	{
		$values = [];
		$used = [];
		$bound = preg_replace_callback(
			self::TOKENS,
			function (array $match) use ($params, &$values, &$used): string {
				if ($match[2] !== null) {
					return $this->prefix.$match[2];
				}
				if ($match[0] === '?') {
					throw new \InvalidArgumentException('Positional "?" in SQL: use named :parameters');
				}
				if ($match[1] === null) {
					return $match[0];
				}
				$name = $match[1];
				if (!array_key_exists($name, $params)) {
					throw new \InvalidArgumentException('Missing SQL parameter :'.$name);
				}
				$used[$name] = true;

				return $this->placeholdersFor($name, $params[$name], $values);
			},
			$sql,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);
		if ($bound === null) {
			throw new \InvalidArgumentException('SQL could not be parsed for parameters: '.preg_last_error_msg());
		}

		$extra = array_diff_key($params, $used);
		if ($extra !== []) {
			throw new \InvalidArgumentException('Unused SQL parameter(s) :'.implode(', :', array_map('strval', array_keys($extra))));
		}

		return new BoundStatement($bound, $values);
	}

	/**
	 * @param list<int|float|string|null> $values Appended with the value(s) of the parameter
	 */
	private function placeholdersFor(string $name, mixed $value, array &$values): string
	{
		if (!is_array($value)) {
			$values[] = $this->scalar($name, $value);

			return '?';
		}
		if ($value === []) {
			return 'NULL';
		}
		foreach ($value as $item) {
			if (is_array($item)) {
				throw new \InvalidArgumentException('SQL parameter :'.$name.' holds a nested array');
			}
			$values[] = $this->scalar($name, $item);
		}

		return implode(', ', array_fill(0, count($value), '?'));
	}

	private function scalar(string $name, mixed $value): int|float|string|null
	{
		if ($value === null || is_int($value) || is_string($value)) {
			return $value;
		}
		if (is_float($value)) {
			if (!is_finite($value)) {
				throw new \InvalidArgumentException('SQL parameter :'.$name.' is not a finite number');
			}

			return $value;
		}
		if (is_bool($value)) {
			return $value ? 1 : 0;
		}
		if ($value instanceof \BackedEnum) {
			return $value->value;
		}
		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d H:i:s');
		}

		throw new \InvalidArgumentException('SQL parameter :'.$name.' has an unsupported type '.get_debug_type($value));
	}
}
