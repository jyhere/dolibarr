<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * natural_search() (core/lib/functions.lib.php) and dol_natural_search_phone() (core/lib/phone.lib.php) with bound
 * parameters: the conditions of the legacy SQL, clause by clause, added to a Query as one parenthesised group (the
 * legacy ' AND (…)'), in the same order and with the same AND/OR precedence, so they find the same rows.
 *
 * Modes, as the legacy ones:
 * - 0 (and any mode not listed): text. Spaces separate criteria joined with AND, except inside "…" or '…'; '|'
 *   separates alternatives joined with OR; '*' is '%'; '!' excludes (NOT LIKE or NULL, the alternatives of such a
 *   criterion then joined with AND); a leading/trailing '^' or '$' anchors; '^$' is empty or NULL. A column
 *   'x.rowid'/'x.id' is an equality on the number. '%' and '_' typed stay wildcards (escape() leaves them).
 * - 1: numbers, with an operator (=, !=, <>, <, <=, >, >=; '=' by default), read by price2num().
 * - 2 / -2: list of integers, IN / NOT IN (or NULL).
 * - 3 / -3: comma separated list of codes, IN / NOT IN (or NULL), '!code' for the opposite.
 * - 4: multiselect values ('a,b' stored), one of the comma separated values.
 * Several columns: OR between them. Empty value (after trim): the query is unchanged.
 *
 * LIKE is 'ilike' (ILIKE on PostgreSQL, as DoliDBPgsql turns the legacy " LIKE '" into ILIKE); equalities and IN
 * stay case sensitive there, as in the legacy SQL. Values the legacy SQL cannot run (an operator unknown to SQL,
 * an empty list item) give a false or no condition instead of an SQL error.
 */
final class NaturalSearch
{
	private const NUMERIC_OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

	private readonly string $priceDecimal;

	private readonly string $priceThousand;

	/**
	 * @param string       $decimalSeparator  $langs->trans('SeparatorDecimal')
	 * @param string       $thousandSeparator $langs->transnoentitiesnoconv('SeparatorThousand'): 'None', 'Space' or
	 *                                        the character (price2num())
	 * @param list<string> $trunkPrefixes     The distinct non empty c_country.trunk_prefix (dol_get_all_trunk_prefixes()),
	 *                                        for applyPhone()
	 */
	public function __construct(
		private readonly string $decimalSeparator,
		string $thousandSeparator = ',',
		private readonly array $trunkPrefixes = []
	) {
		// price2num(): untranslated keys fall back to ',' and ' '
		$this->priceDecimal = $decimalSeparator !== 'SeparatorDecimal' ? $decimalSeparator : ',';
		$thousand = $thousandSeparator !== 'SeparatorThousand' ? $thousandSeparator : ' ';
		$this->priceThousand = match ($thousand) {
			'None' => '',
			'Space' => ' ',
			default => $thousand,
		};
	}

	/**
	 * natural_search($columns, $value, $mode) as a condition of $query (or of the where(Closure) group given).
	 *
	 * @template T of object
	 * @param Query<T>            $query
	 * @param string|list<string> $columns Columns as where() takes them, named by the code
	 * @return Query<T> $query
	 * @throws \InvalidArgumentException Unknown, invalid or secret column
	 */
	public function apply(Query $query, string|array $columns, string $value, int $mode = 0): Query
	{
		$value = trim($value);
		if ($value === '') {
			return $query;
		}
		if ($mode === 0) {
			$value = str_replace('*', '%', $value);
		}
		if ($mode === 1) {
			// '< 10' becomes '<10', so the split on spaces keeps the operator with its number
			$value = (string) preg_replace('/([!<>=]+)\s+([0-9'.preg_quote($this->decimalSeparator, '/').'\-])/', '\1\2', $value);
		}
		$value = (string) preg_replace('/\s*\|\s*/', '|', $value);
		$criteria = $mode !== 3 && $mode !== -3 ? self::splitKeepingQuotes($value) : [$value];

		$fields = [];
		foreach (is_array($columns) ? $columns : [$columns] as $column) {
			$fields[] = ['name' => $column, 'sql' => $query->column($column)];
		}

		$query->where(function (Query $outer) use ($criteria, $fields, $mode): void {
			foreach ($criteria as $criterion) {
				[$atoms, $fieldCount] = $this->criterion(trim($criterion), $fields, $mode);
				if ($atoms === []) {
					continue;
				}
				if ($fieldCount > 1) {
					$outer->where(static function (Query $group) use ($atoms): void {
						self::emit($group, $atoms);
					});
				} else {
					self::emit($outer, $atoms);
				}
			}
		});

		return $query;
	}

	/**
	 * dol_natural_search_phone(): the number without spaces, dashes, dots and brackets, and without each known
	 * trunk prefix it starts with (unless it starts with '+'), as text alternatives.
	 *
	 * @template T of object
	 * @param Query<T>            $query
	 * @param string|list<string> $columns
	 * @return Query<T> $query
	 * @throws \InvalidArgumentException Unknown, invalid or secret column
	 */
	public function applyPhone(Query $query, string|array $columns, string $value): Query
	{
		$value = trim($value);
		if ($value === '') {
			return $query;
		}
		$stripped = (string) preg_replace('/[\s\-\.\(\)]/', '', $value);
		$candidates = [$stripped];
		if (!str_starts_with($stripped, '+')) {
			foreach ($this->trunkPrefixes as $prefix) {
				if ($prefix !== '' && str_starts_with($stripped, $prefix)) {
					$candidates[] = substr($stripped, strlen($prefix));
				}
			}
		}
		$candidates = array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => $candidate !== '')));
		if ($candidates === []) {
			$candidates = [$value];
		}

		return $this->apply($query, $columns, implode('|', $candidates), 0);
	}

	/**
	 * The conditions of one AND criterion on every column, each with the AND/OR the legacy SQL writes before it,
	 * and the count of the legacy $i2 (more than one: the criterion is parenthesised).
	 *
	 * @param list<array{name: string, sql: string}> $fields
	 * @return array{list<array{string, \Closure(Query<object>, string): void}>, int}
	 */
	private function criterion(string $criterion, array $fields, int $mode): array
	{
		$atoms = [];
		$fieldCount = 0;
		foreach ($fields as $field) {
			if ($mode === 1) {
				foreach (explode('|', $criterion) as $part) {
					if ($part === '') {
						continue;
					}
					$part = trim($part);
					$number = (string) preg_replace('/([!<>=]+)/', '', $part);
					$operator = preg_match('/([!<>=]+)/', $part, $match) === 1 ? $match[1] : '=';
					if ($number !== '') {
						$atoms[] = ['OR', $this->comparison($field['sql'], $operator, $number)];
					}
				}
				$fieldCount++;
			} elseif ($mode === 2 || $mode === -2) {
				$atoms[] = ['OR', self::raw($field['sql'].($mode === -2 ? ' NOT' : '').' IN (:ids)', ['ids' => self::integers($criterion)])];
				if ($mode === -2) {
					$atoms[] = ['OR', self::isNull($field['name'])];
				}
				$fieldCount++;
			} elseif ($mode === 3 || $mode === -3) {
				$atom = self::codes($field['sql'], $criterion, $mode === -3);
				if ($atom !== null) {
					$atoms[] = ['OR', $atom];
				}
				$fieldCount++;
				if ($mode === -3) {
					$atoms[] = ['OR', self::isNull($field['name'])];
				}
			} elseif ($mode === 4) {
				foreach (explode(',', $criterion) as $item) {
					$item = trim($item);
					if ($item === '' || $item === '0') {
						continue;
					}
					$atoms[] = ['OR', self::group([
						['AND', self::like($field['name'], false, $item.',%')],
						['OR', self::raw($field['sql'].' = :value', ['value' => $item])],
						['OR', self::like($field['name'], false, '%,'.$item)],
						['OR', self::like($field['name'], false, '%,'.$item.',%')],
					])];
					$fieldCount++;
				}
			} else {
				array_push($atoms, ...self::text($field, $criterion));
				$fieldCount++;
			}
		}

		return [$atoms, $fieldCount];
	}

	/**
	 * Mode 0 on one column.
	 *
	 * @param array{name: string, sql: string} $field
	 * @return list<array{string, \Closure(Query<object>, string): void}>
	 */
	private static function text(array $field, string $criterion): array
	{
		$atoms = [];
		$excluding = str_starts_with($criterion, '!');
		foreach (explode('|', $criterion) as $part) {
			if ($part === '') {
				continue;
			}
			$part = trim($part);
			// Searching empty or excluding: the alternatives (and columns) must all hold
			$boolean = $part === '^$' || $excluding ? 'AND' : 'OR';
			if (preg_match('/\.(id|rowid)$/', $field['sql']) === 1) {
				$atoms[] = [$boolean, self::raw($field['sql'].' = :value', ['value' => is_numeric($part) ? (float) $part : 0])];

				continue;
			}
			$pattern = $part;
			$before = '%';
			$after = '%';
			$negated = str_starts_with($part, '!');
			if ($negated) {
				$pattern = substr($pattern, 1);
			}
			if (preg_match('/^[\^\$]/', $part) === 1) {
				$before = '';
				$pattern = (string) preg_replace('/^[\^\$]/', '', $pattern);
			}
			if (preg_match('/[\^\$]$/', $part) === 1) {
				$after = '';
				$pattern = (string) preg_replace('/[\^\$]$/', '', $pattern);
			}
			$like = self::like($field['name'], $negated, $before.$pattern.$after);
			$atoms[] = [$boolean, $pattern === '' || $negated ? self::group([['AND', $like], ['OR', self::isNull($field['name'])]]) : $like];
		}

		return $atoms;
	}

	/**
	 * Mode 1: one comparison, or a false condition for what is not a number (the legacy '1 = 2') or an operator
	 * SQL does not know (an SQL error in the legacy).
	 *
	 * @return \Closure(Query<object>, string): void
	 */
	private function comparison(string $columnSql, string $operator, string $number): \Closure
	{
		$numeric = $this->price2num($number);
		if (!is_numeric($numeric) || !in_array($operator, self::NUMERIC_OPERATORS, true)) {
			return self::raw('1 = 2', []);
		}

		return self::raw($columnSql.' '.$operator.' :value', ['value' => (float) $numeric]);
	}

	/**
	 * Mode 2: the digits, '-' and ',' kept, as integers; nothing left is the legacy 'IN (0)'. An item the legacy
	 * would have written as is but is not an integer ('', '-', '1-2') is left out.
	 *
	 * @return non-empty-list<int>
	 */
	private static function integers(string $criterion): array
	{
		$integers = [];
		foreach (explode(',', (string) preg_replace('/[^\-0-9,]/', '', $criterion)) as $item) {
			if (preg_match('/^-?[0-9]+$/D', $item) === 1) {
				$integers[] = (int) $item;
			}
		}

		return $integers === [] ? [0] : $integers;
	}

	/**
	 * Mode 3 (-3 negated) on one column: IN the codes and NOT IN the '!codes', null when there is none.
	 *
	 * @return (\Closure(Query<object>, string): void)|null
	 */
	private static function codes(string $columnSql, string $criterion, bool $negated): ?\Closure
	{
		$included = [];
		$excluded = [];
		foreach (explode(',', $criterion) as $item) {
			$item = trim($item);
			if ($item === '') {
				continue;
			}
			if (str_starts_with($item, '!')) {
				$excluded[] = self::sanitizeCode((string) preg_replace('/^!=?/', '', $item));
			} else {
				$included[] = self::sanitizeCode($item);
			}
		}
		$parts = [];
		if ($included !== []) {
			$parts[] = ['AND', self::raw($columnSql.($negated ? ' NOT IN' : ' IN').' (:codes)', ['codes' => $included])];
		}
		if ($excluded !== []) {
			$parts[] = ['AND', self::raw($columnSql.($negated ? ' IN' : ' NOT IN').' (:codes)', ['codes' => $excluded])];
		}

		return match (count($parts)) {
			0 => null,
			1 => $parts[0][1],
			default => self::group($parts),
		};
	}

	/**
	 * What DoliDB::sanitize($list, 1, 0, 1) leaves of a quoted code: digits, letters, '_', '-', '.', ',' and
	 * spaces, without its inner quotes.
	 */
	private static function sanitizeCode(string $code): string
	{
		return str_replace("'", '', (string) preg_replace('/[^0-9_\-\.,\p{L}\' ]/ui', '', $code));
	}

	/**
	 * dolExplodeKeepIfQuotes(): the words, a "…" or '…' section being one word, empty ones left out.
	 *
	 * @return list<string>
	 */
	private static function splitKeepingQuotes(string $input): array
	{
		preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $input, $matches);
		$words = [];
		foreach (array_keys($matches[0]) as $index) {
			foreach ([$matches[1][$index], $matches[2][$index], $matches[3][$index]] as $word) {
				if ($word !== '') {
					$words[] = $word;

					break;
				}
			}
		}

		return $words;
	}

	/**
	 * price2num($amount) (no rounding): the number in the PHP/SQL format, '' or garbage when it is none.
	 */
	private function price2num(string $amount): string
	{
		$decimal = $this->priceDecimal;
		$thousand = $this->priceThousand;
		if (!is_numeric($amount)) {
			$amount = (string) preg_replace('/[a-zA-Z\/\\\*\(\)\<\>\_]/', '', $amount);
		}
		if (is_numeric($amount)) {
			$fraction = sprintf('%10.10F', (float) $amount - intval($amount));
			$fraction = (string) preg_replace('/([\.1-9])0+$/', '\\1', $fraction);
			$amount = number_format((float) $amount, max(0, mb_strlen($fraction) - 2), $decimal, $thousand);
		}
		if ($thousand !== ',' && $thousand !== '.') {
			// Both decimal points accepted: the last '.' or ',' is the decimal one
			if (preg_match('/^(?<int>[^,]*,|[^.]*\.)(?<dec>[^.,]*)$/u', $amount, $matches) === 1) {
				$amount = str_replace(['.', ','], '', $matches['int']).$decimal.$matches['dec'];
			}
		}
		$amount = str_replace(' ', '', $amount);
		if ($thousand !== '') {
			$amount = str_replace($thousand, '', $amount);
		}
		if ($decimal !== '') {
			$amount = str_replace($decimal, '.', $amount);
		}

		return (string) preg_replace('/[^0-9\-\.]/', '', $amount);
	}

	/**
	 * Adds the conditions in order, the first one joined with AND (to the previous criterion, or first of a group).
	 *
	 * @param Query<object>                                              $target
	 * @param list<array{string, \Closure(Query<object>, string): void}> $atoms
	 */
	private static function emit(Query $target, array $atoms): void
	{
		foreach ($atoms as $index => [$boolean, $atom]) {
			$atom($target, $index === 0 ? 'AND' : $boolean);
		}
	}

	/**
	 * @return \Closure(Query<object>, string): void
	 */
	private static function like(string $column, bool $negated, string $pattern): \Closure
	{
		$operator = $negated ? 'not ilike' : 'ilike';

		return static function (Query $target, string $boolean) use ($column, $operator, $pattern): void {
			if ($boolean === 'OR') {
				$target->orWhere($column, $operator, $pattern);
			} else {
				$target->where($column, $operator, $pattern);
			}
		};
	}

	/**
	 * @return \Closure(Query<object>, string): void
	 */
	private static function isNull(string $column): \Closure
	{
		return static function (Query $target, string $boolean) use ($column): void {
			if ($boolean === 'OR') {
				$target->orWhere($column, '=', null);
			} else {
				$target->where($column, '=', null);
			}
		};
	}

	/**
	 * Code-built SQL on a column resolved by Query::column(), its values bound.
	 *
	 * @param array<string, mixed> $params
	 * @return \Closure(Query<object>, string): void
	 */
	private static function raw(string $sql, array $params): \Closure
	{
		return static function (Query $target, string $boolean) use ($sql, $params): void {
			if ($boolean === 'OR') {
				$target->orWhereRaw($sql, $params);
			} else {
				$target->whereRaw($sql, $params);
			}
		};
	}

	/**
	 * @param list<array{string, \Closure(Query<object>, string): void}> $atoms
	 * @return \Closure(Query<object>, string): void
	 */
	private static function group(array $atoms): \Closure
	{
		$conditions = static function (Query $group) use ($atoms): void {
			self::emit($group, $atoms);
		};

		return static function (Query $target, string $boolean) use ($conditions): void {
			if ($boolean === 'OR') {
				$target->orWhere($conditions);
			} else {
				$target->where($conditions);
			}
		};
	}
}
