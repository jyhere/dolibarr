<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

use Dolibarr\Core\Database\SqlLiterals;

/**
 * One WHERE condition of a Query, its column already resolved through the mapping and its value already cast:
 * compiling it only names the parameters. $boolean ('AND' or 'OR') joins it to the previous condition.
 */
final class Condition
{
	private const COMPARISON = 'comparison';
	private const IN = 'in';
	private const IN_SUBQUERY = 'inSubquery';
	private const NULL_CHECK = 'nullCheck';
	private const EXISTS = 'exists';
	private const RAW = 'raw';
	private const GROUP = 'group';
	private const CONSTANT = 'constant';

	/**
	 * @param string               $sql      Column SQL ('t.login'), raw SQL or constant condition
	 * @param array<string, mixed> $params   whereRaw() parameters
	 * @param list<Condition>      $children Conditions of a group
	 */
	private function __construct(
		public readonly string $boolean,
		private readonly string $kind,
		private readonly string $sql,
		private readonly string $operator = '',
		private readonly mixed $value = null,
		private readonly bool $negated = false,
		private readonly ?Query $subquery = null,
		private readonly array $params = [],
		private readonly array $children = []
	) {
	}

	/**
	 * @param string $operator One of Query::OPERATORS
	 */
	public static function comparison(string $boolean, string $column, string $operator, mixed $value): self
	{
		return new self($boolean, self::COMPARISON, $column, $operator, $value);
	}

	/**
	 * An empty list is a false condition (true when negated), never 'IN ()'.
	 *
	 * @param list<mixed> $values
	 */
	public static function in(string $boolean, string $column, bool $negated, array $values): self
	{
		if ($values === []) {
			return new self($boolean, self::CONSTANT, $negated ? '1 = 1' : '1 = 0');
		}

		return new self($boolean, self::IN, $column, '', $values, $negated);
	}

	public static function inSubquery(string $boolean, string $column, bool $negated, Query $subquery): self
	{
		return new self($boolean, self::IN_SUBQUERY, $column, '', null, $negated, $subquery);
	}

	public static function nullCheck(string $boolean, string $column, bool $negated): self
	{
		return new self($boolean, self::NULL_CHECK, $column, '', null, $negated);
	}

	public static function exists(string $boolean, Query $subquery): self
	{
		return new self($boolean, self::EXISTS, '', '', null, false, $subquery);
	}

	/**
	 * @param array<string, mixed> $params Values of the ':name' placeholders of the SQL
	 * @throws \InvalidArgumentException A placeholder without value, or a value without placeholder
	 */
	public static function raw(string $boolean, string $sql, array $params, string $dialect): self
	{
		self::renameParameters($sql, $params, $dialect, new QueryParameters());

		return new self($boolean, self::RAW, $sql, '', null, false, null, $params);
	}

	/**
	 * @param list<Condition> $children
	 */
	public static function group(string $boolean, array $children): self
	{
		return new self($boolean, self::GROUP, '', '', null, false, null, [], $children);
	}

	/**
	 * @param list<Condition> $conditions
	 */
	public static function compileList(array $conditions, Grammar $grammar, QueryParameters $parameters, bool $caseInsensitive): string
	{
		$sql = '';
		foreach ($conditions as $condition) {
			$compiled = $condition->compile($grammar, $parameters, $caseInsensitive);
			$sql .= $sql === '' ? $compiled : ' '.$condition->boolean.' '.$compiled;
		}

		return $sql;
	}

	/**
	 * @param list<Condition> $conditions
	 */
	public static function hasOr(array $conditions): bool
	{
		foreach (array_slice($conditions, 1) as $condition) {
			if ($condition->boolean === 'OR') {
				return true;
			}
		}

		return false;
	}

	public function compile(Grammar $grammar, QueryParameters $parameters, bool $caseInsensitive): string
	{
		$not = $this->negated ? 'NOT ' : '';

		return match ($this->kind) {
			self::COMPARISON => $this->sql.' '.$grammar->operator($this->operator, $caseInsensitive).' '.$parameters->add($this->value),
			self::IN => $this->sql.' '.$not.'IN ('.$parameters->add($this->value).')',
			self::IN_SUBQUERY => $this->sql.' '.$not.'IN ('.$this->subquery()->compileSelect($grammar, $parameters).')',
			self::NULL_CHECK => $this->sql.' IS '.$not.'NULL',
			self::EXISTS => 'EXISTS ('.$this->subquery()->compileSelect($grammar, $parameters).')',
			self::RAW => '('.self::renameParameters($this->sql, $this->params, $grammar->dialect(), $parameters).')',
			self::GROUP => '('.self::compileList($this->children, $grammar, $parameters, $caseInsensitive).')',
			default => $this->sql,
		};
	}

	private function subquery(): Query
	{
		return $this->subquery ?? throw new \LogicException('Condition '.$this->kind.' without subquery');
	}

	/**
	 * Each ':name' of the SQL (outside literals and '::' casts) becomes the placeholder of its value.
	 *
	 * @param array<string, mixed> $params
	 * @throws \InvalidArgumentException
	 */
	private static function renameParameters(string $sql, array $params, string $dialect, QueryParameters $parameters): string
	{
		$placeholders = [];
		$renamed = preg_replace_callback(
			'/'.SqlLiterals::pattern($dialect).'|::|:([a-zA-Z_][a-zA-Z0-9_]*)/s',
			static function (array $match) use ($params, $parameters, &$placeholders): string {
				$name = $match[1] ?? '';
				if ($name === '') {
					return $match[0];
				}
				if (!array_key_exists($name, $params)) {
					throw new \InvalidArgumentException('Missing whereRaw() parameter :'.$name);
				}

				return $placeholders[$name] ??= $parameters->add($params[$name]);
			},
			$sql
		);
		if ($renamed === null) {
			throw new \InvalidArgumentException('whereRaw() SQL could not be parsed: '.preg_last_error_msg());
		}
		$unused = array_diff_key($params, $placeholders);
		if ($unused !== []) {
			throw new \InvalidArgumentException('Unused whereRaw() parameter(s) :'.implode(', :', array_keys($unused)));
		}

		return $renamed;
	}
}
