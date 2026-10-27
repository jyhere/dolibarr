<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

use Dolibarr\Core\Database\LegacyDriver;
use Dolibarr\Core\Database\ParameterBinder;
use Dolibarr\Core\Orm\Cast\CastRegistry;

/**
 * Fluent SELECT on one mapped entity, compiled by a Grammar into SQL with bound parameters (CompiledQuery).
 *
 * Columns: a property or column name of the mapping, 'alias.column' for the main alias (mapped too; also for a
 * join on the entity's own table) or for the alias of another join declared before (any identifier: the joined
 * table is named by the code). Anything else throws
 * \InvalidArgumentException, so a name coming from a request never reaches the SQL unchecked; for a sort
 * column from the URL the repository asks isSortable() first. Values are bound, cast by their column.
 *
 * Multicompany: an entity with an 'entity' column gets 'alias.entity IN (:entities)' (the EntityScope) unless
 * withoutEntityScope(). Execution (get, first, count…) belongs to the QueryExecutor given by the Repository.
 */
final class Query
{
	public const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];

	private const ALIAS = '/^[a-z_][a-z0-9_]*$/D';
	private const TABLE = '/^[a-z0-9_]+$/D';
	private const COLUMN = '/^(?:([a-z_][a-z0-9_]*)\.)?([a-zA-Z_][a-zA-Z0-9_]*)$/D';
	private const SELECT_AS = '/^(\S+)\s+AS\s+([a-zA-Z_][a-zA-Z0-9_]*)$/Di';
	private const OUTPUT_ALIAS = '/^[a-zA-Z_][a-zA-Z0-9_]*$/D';

	/** @var list<string|array{expression: string, alias: string, params: array<string, mixed>}> Resolved column SQL, or a selectRaw() */
	private array $columns = [];

	/** @var list<Condition> */
	private array $conditions = [];

	/**
	 * Aliases declared by join()/leftJoin(): their table (without prefix), and their declared columns, by the
	 * mapping of an entity or by name (null: none declared).
	 *
	 * @var array<string, array{table: string, mapping: ?EntityMapping, columns: ?list<string>}>
	 */
	private array $joinAliases = [];

	/** @var list<string> */
	private array $joins = [];

	/** @var list<string> */
	private array $orders = [];

	/** @var list<string> */
	private array $groups = [];

	/** @var list<string> */
	private array $relations = [];

	/** @var array{where: list<string>, select: list<string>, from: list<string>} */
	private array $legacyFragments = ['where' => [], 'select' => [], 'from' => []];

	private ?int $limit = null;

	private ?int $offset = null;

	private bool $distinct = false;

	private bool $entityScoped = true;

	private bool $caseInsensitive = false;

	/** A where(Closure) group: only where-family calls are allowed on it. */
	private bool $isGroup = false;

	/**
	 * @param EntityScope|null   $entityScope Required to compile an entity with an 'entity' column (unless withoutEntityScope())
	 * @param QueryExecutor|null $executor    Runs get(), first()…; null for a query only compiled
	 * @param string             $alias       Main alias ('t')
	 * @throws \InvalidArgumentException When the alias is not a lower case identifier
	 */
	public function __construct(
		private readonly EntityMapping $mapping,
		private readonly Grammar $grammar,
		private readonly ?EntityScope $entityScope = null,
		private readonly ?QueryExecutor $executor = null,
		private readonly string $alias = 't',
		private readonly CastRegistry $casts = new CastRegistry(),
		private readonly MappingRegistry $mappings = new MappingRegistry()
	) {
		if (preg_match(self::ALIAS, $alias) !== 1) {
			throw new \InvalidArgumentException('Invalid query alias "'.$alias.'"');
		}
	}

	public function mapping(): EntityMapping
	{
		return $this->mapping;
	}

	public function alias(): string
	{
		return $this->alias;
	}

	/**
	 * Selected columns (default: every mapped column of the main alias, never an unmapped one), each optionally
	 * renamed: 'a.name AS author_name'.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function select(string ...$columns): static
	{
		$this->refuseInGroup('select');
		foreach ($columns as $column) {
			if (preg_match(self::SELECT_AS, $column, $match) === 1) {
				$this->columns[] = $this->resolve($match[1])[0].' AS '.$match[2];
			} else {
				$this->columns[] = $this->resolve($column)[0];
			}
		}

		return $this;
	}

	/**
	 * A computed column written by the code, a constant expression such as
	 * "CASE WHEN t.api_key IS NOT NULL AND t.api_key <> '' THEN 1 ELSE 0 END": never request input. Its ':name'
	 * placeholders are bound to $params, checked as whereRaw() does.
	 *
	 * @param array<string, mixed> $params
	 * @throws \InvalidArgumentException Invalid alias, a placeholder without value or a value without placeholder
	 */
	public function selectRaw(string $expression, string $alias, array $params = []): static
	{
		$this->refuseInGroup('selectRaw');
		if (preg_match(self::OUTPUT_ALIAS, $alias) !== 1) {
			throw new \InvalidArgumentException('Invalid column alias "'.$alias.'"');
		}
		Condition::bindRaw($expression, $params, $this->grammar->dialect(), new QueryParameters());
		$this->columns[] = ['expression' => $expression, 'alias' => $alias, 'params' => $params];

		return $this;
	}

	/**
	 * where('status', 1) is an equality (two arguments, whatever the value); where('price', '<', 5) takes one of
	 * OPERATORS; a null value with '=' or '!='/'<>' is IS [NOT] NULL. A Closure(Query): void adds a parenthesised
	 * group of the conditions it adds to the Query it receives.
	 *
	 * @throws \InvalidArgumentException Unknown column, unsupported operator or value
	 */
	public function where(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static
	{
		return $this->addWhere('AND', func_num_args(), $column, $operatorOrValue, $value);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function orWhere(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static
	{
		return $this->addWhere('OR', func_num_args(), $column, $operatorOrValue, $value);
	}

	/**
	 * An empty list is a false condition.
	 *
	 * @param array<mixed>|Query $values
	 * @throws \InvalidArgumentException
	 */
	public function whereIn(string $column, array|Query $values): static
	{
		return $this->addIn($column, $values, false);
	}

	/**
	 * An empty list is a true condition.
	 *
	 * @param array<mixed>|Query $values
	 * @throws \InvalidArgumentException
	 */
	public function whereNotIn(string $column, array|Query $values): static
	{
		return $this->addIn($column, $values, true);
	}

	public function whereNull(string $column): static
	{
		$this->conditions[] = Condition::nullCheck('AND', $this->resolve($column)[0], false);

		return $this;
	}

	public function whereNotNull(string $column): static
	{
		$this->conditions[] = Condition::nullCheck('AND', $this->resolve($column)[0], true);

		return $this;
	}

	/**
	 * The subquery may refer to this query's alias in a whereRaw() (correlated subquery).
	 */
	public function whereExists(Query $subquery): static
	{
		$this->conditions[] = Condition::exists('AND', $subquery, false);

		return $this;
	}

	public function whereNotExists(Query $subquery): static
	{
		$this->conditions[] = Condition::exists('AND', $subquery, true);

		return $this;
	}

	/**
	 * SQL written by the code (portable MySQL/PostgreSQL), parenthesised, its ':name' placeholders bound to
	 * $params (an array value is a list, as for Connection). Never a value or a name from the request.
	 *
	 * @param array<string, mixed> $params
	 * @throws \InvalidArgumentException A placeholder without value, or a value without placeholder
	 */
	public function whereRaw(string $sql, array $params = []): static
	{
		$this->conditions[] = Condition::raw('AND', $sql, $params, $this->grammar->dialect());

		return $this;
	}

	/**
	 * Raw SQL of the legacy hooks (printFieldListSelect/From/Where) and extrafields search, the only unbound SQL
	 * a Query accepts; reserved to them. Inserted as is: $select after the columns, $from after the joins,
	 * $where as ' AND (…)', or as is when it starts with AND/OR (as the legacy lists do). PgsqlGrammar converts
	 * them from MySQL first. They go through Connection's parameter parsing: no '?', ':name' or '@name' outside
	 * literals.
	 */
	public function legacyFragment(string $where = '', string $select = '', string $from = ''): static
	{
		$this->refuseInGroup('legacyFragment');
		foreach (['where' => $where, 'select' => $select, 'from' => $from] as $part => $fragment) {
			if ($fragment !== '') {
				$this->legacyFragments[$part][] = $fragment;
			}
		}

		return $this;
	}

	/**
	 * INNER JOIN '@'.$table AS $alias ON $leftColumn = $rightColumn.
	 *
	 * The columns of the alias may be declared: by an entity class (its mapping, which then also refuses unmapped
	 * columns and casts values) or by a list of names. Only declared columns are sortable (isSortable()); a join on
	 * the entity's own table uses its mapping. Secret columns (MappingReader::SECRET_COLUMNS) are refused on any
	 * alias.
	 *
	 * @param string                   $table   Table without prefix, named by the code
	 * @param class-string|list<string>|null $columns Entity class or column names of the alias
	 * @throws \InvalidArgumentException Invalid table, alias, declaration or column, alias already used
	 */
	public function join(string $table, string $alias, string $leftColumn, string $rightColumn, string|array|null $columns = null): static
	{
		return $this->addJoin('INNER JOIN', $table, $alias, $leftColumn, $rightColumn, $columns);
	}

	/**
	 * @param class-string|list<string>|null $columns
	 * @throws \InvalidArgumentException
	 */
	public function leftJoin(string $table, string $alias, string $leftColumn, string $rightColumn, string|array|null $columns = null): static
	{
		return $this->addJoin('LEFT JOIN', $table, $alias, $leftColumn, $rightColumn, $columns);
	}

	/**
	 * @param string $direction ASC or DESC (any case), anything else is ASC
	 * @throws \InvalidArgumentException Unknown column (a column from the request: check isSortable() first)
	 */
	public function orderBy(string $column, string $direction = 'ASC'): static
	{
		$this->refuseInGroup('orderBy');
		$direction = strtoupper(trim($direction));
		$this->orders[] = $this->resolve($column)[0].' '.($direction === 'DESC' ? 'DESC' : 'ASC');

		return $this;
	}

	/**
	 * Whether a column from the request may be sorted on: a mapped column of the main alias (or of a join on the
	 * entity's own table, 'u2.login'), or a declared column of a joined alias (join() $columns). Undeclared joined
	 * columns, secret columns, expressions and anything else are refused.
	 */
	public function isSortable(string $column): bool
	{
		try {
			return $this->resolve($column)[2];
		} catch (\InvalidArgumentException) {
			return false;
		}
	}

	public function groupBy(string ...$columns): static
	{
		$this->refuseInGroup('groupBy');
		foreach ($columns as $column) {
			$this->groups[] = $this->resolve($column)[0];
		}

		return $this;
	}

	/**
	 * @throws \InvalidArgumentException When negative
	 */
	public function limit(int $limit): static
	{
		$this->refuseInGroup('limit');
		if ($limit < 0) {
			throw new \InvalidArgumentException('Negative query limit');
		}
		$this->limit = $limit;

		return $this;
	}

	/**
	 * @throws \InvalidArgumentException When negative
	 */
	public function offset(int $offset): static
	{
		$this->refuseInGroup('offset');
		if ($offset < 0) {
			throw new \InvalidArgumentException('Negative query offset');
		}
		$this->offset = $offset;

		return $this;
	}

	public function distinct(): static
	{
		$this->refuseInGroup('distinct');
		$this->distinct = true;

		return $this;
	}

	/**
	 * No 'entity IN (…)' filter: login, multicompany administration. To be called explicitly, never by default.
	 */
	public function withoutEntityScope(): static
	{
		$this->refuseInGroup('withoutEntityScope');
		$this->entityScoped = false;

		return $this;
	}

	/**
	 * 'like' compiles to ILIKE on PostgreSQL (MySQL collations are already case insensitive).
	 */
	public function caseInsensitive(): static
	{
		$this->refuseInGroup('caseInsensitive');
		$this->caseInsensitive = true;

		return $this;
	}

	/**
	 * Relations to load with the entities (run by the executor).
	 *
	 * @throws \InvalidArgumentException When the entity has no such relation
	 */
	public function with(string ...$relations): static
	{
		$this->refuseInGroup('with');
		foreach ($relations as $relation) {
			if (!isset($this->mapping->relations[$relation])) {
				throw new \InvalidArgumentException($this->mapping->class.' has no relation "'.$relation.'"');
			}
			if (!in_array($relation, $this->relations, true)) {
				$this->relations[] = $relation;
			}
		}

		return $this;
	}

	/**
	 * @return list<string>
	 */
	public function relations(): array
	{
		return $this->relations;
	}

	/**
	 * @throws \LogicException When the entity scope applies and no EntityScope was given
	 */
	public function compile(): CompiledQuery
	{
		$this->refuseInGroup('compile');
		$parameters = new QueryParameters();
		$sql = $this->compileSelect($this->grammar, $parameters);

		return new CompiledQuery($sql, $parameters->all());
	}

	/**
	 * 'SELECT COUNT(*) AS total' of the rows the query matches, without order, limit or offset; a DISTINCT or
	 * GROUP BY query is counted as a subquery (portable, unlike a multi-column COUNT(DISTINCT …)).
	 *
	 * @throws \LogicException When the entity scope applies and no EntityScope was given
	 */
	public function compileCount(): CompiledQuery
	{
		$this->refuseInGroup('compileCount');
		$parameters = new QueryParameters();
		if ($this->distinct || $this->groups !== []) {
			$sql = 'SELECT COUNT(*) AS total FROM ('.$this->selectList($this->grammar, $parameters).$this->body($this->grammar, $parameters).$this->groupClause().') AS counted';
		} else {
			$sql = 'SELECT COUNT(*) AS total'.$this->body($this->grammar, $parameters);
		}

		return new CompiledQuery($sql, $parameters->all());
	}

	/**
	 * The same SQL in MySQL dialect, each value interpolated as LegacyDriver does (DoliDB::escape(), numbers as
	 * literals), the prefix resolved: for the legacy code that receives the SQL of a list (list_print_total,
	 * printFieldListFooter) and runs it through DoliDB, which converts it for its engine. Handed to the legacy
	 * code, never executed by the MVC core.
	 *
	 * @throws \LogicException When the entity scope applies and no EntityScope was given
	 */
	public function toLegacySql(\DoliDB $db): string
	{
		$this->refuseInGroup('toLegacySql');
		$parameters = new QueryParameters();
		$sql = $this->compileSelect(new MysqlGrammar(), $parameters);
		$statement = (new ParameterBinder((string) $db->prefix(), $db->type === 'pgsql' ? 'pgsql' : 'mysql'))->bind($sql, $parameters->all());

		return (new LegacyDriver($db))->interpolate($statement);
	}

	/**
	 * The SELECT of this query, its parameters added to $parameters (also used for subqueries).
	 *
	 * @internal
	 * @throws \LogicException When the entity scope applies and no EntityScope was given
	 */
	public function compileSelect(Grammar $grammar, QueryParameters $parameters): string
	{
		$order = $this->orders === [] ? '' : ' ORDER BY '.implode(', ', $this->orders);

		return $this->selectList($grammar, $parameters).$this->body($grammar, $parameters).$this->groupClause().$order.$grammar->limit($this->limit, $this->offset);
	}

	/**
	 * @return list<object>
	 * @throws \LogicException Without executor
	 */
	public function get(): array
	{
		return $this->executor()->get($this);
	}

	/**
	 * @throws \LogicException Without executor
	 */
	public function first(): ?object
	{
		return $this->executor()->first($this);
	}

	/**
	 * @throws \LogicException Without executor
	 */
	public function count(): int
	{
		return $this->executor()->count($this);
	}

	/**
	 * @throws \LogicException Without executor
	 */
	public function exists(): bool
	{
		return $this->executor()->exists($this);
	}

	/**
	 * @throws \LogicException Without executor
	 */
	public function paginate(int $page, int $perPage): object
	{
		return $this->executor()->paginate($this, $page, $perPage);
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws \LogicException Without executor
	 */
	public function rows(): array
	{
		return $this->executor()->rows($this);
	}

	/**
	 * @throws \LogicException On a where(Closure) group, which only keeps its conditions
	 */
	private function refuseInGroup(string $method): void
	{
		if ($this->isGroup) {
			throw new \LogicException($method.'() on a where(Closure) group: only where-family conditions are allowed there');
		}
	}

	private function executor(): QueryExecutor
	{
		return $this->executor ?? throw new \LogicException('This Query has no executor: build it from a Repository to run it');
	}

	private function addWhere(string $boolean, int $argumentCount, string|\Closure $column, mixed $operatorOrValue, mixed $value): static
	{
		if ($column instanceof \Closure) {
			$group = new self($this->mapping, $this->grammar, null, null, $this->alias, $this->casts, $this->mappings);
			$group->joinAliases = $this->joinAliases;
			$group->isGroup = true;
			$column($group);
			if ($group->conditions !== []) {
				$this->conditions[] = Condition::group($boolean, $group->conditions);
			}

			return $this;
		}
		if ($argumentCount < 2) {
			throw new \InvalidArgumentException('where("'.$column.'") without value');
		}
		if ($argumentCount === 2) {
			$operator = '=';
			$value = $operatorOrValue;
		} else {
			$operator = $this->operator($operatorOrValue);
		}
		[$sql, $mappedColumn] = $this->resolve($column);
		if ($value === null) {
			if (!in_array($operator, ['=', '!=', '<>'], true)) {
				throw new \InvalidArgumentException('Null value with operator "'.$operator.'" on '.$column);
			}
			$this->conditions[] = Condition::nullCheck($boolean, $sql, $operator !== '=');

			return $this;
		}
		$this->conditions[] = Condition::comparison($boolean, $sql, $operator, $this->bindable($mappedColumn, $operator, $value, $column));

		return $this;
	}

	/**
	 * @param array<mixed>|Query $values
	 */
	private function addIn(string $column, array|Query $values, bool $negated): static
	{
		[$sql, $mappedColumn] = $this->resolve($column);
		if ($values instanceof Query) {
			$this->conditions[] = Condition::inSubquery('AND', $sql, $negated, $values);

			return $this;
		}
		$bound = [];
		foreach ($values as $item) {
			$bound[] = $this->bindable($mappedColumn, '=', $item, $column);
		}
		$this->conditions[] = Condition::in('AND', $sql, $negated, $bound);

		return $this;
	}

	/**
	 * @param class-string|list<string>|null $columns
	 */
	private function addJoin(string $type, string $table, string $alias, string $leftColumn, string $rightColumn, string|array|null $columns): static
	{
		$this->refuseInGroup($type === 'LEFT JOIN' ? 'leftJoin' : 'join');
		if (preg_match(self::TABLE, $table) !== 1) {
			throw new \InvalidArgumentException('Invalid join table "'.$table.'"');
		}
		if (preg_match(self::ALIAS, $alias) !== 1 || $alias === $this->alias || isset($this->joinAliases[$alias])) {
			throw new \InvalidArgumentException('Invalid or already used join alias "'.$alias.'"');
		}
		$this->joinAliases[$alias] = $this->joinDeclaration($table, $columns);
		try {
			$this->joins[] = ' '.$type.' @'.$table.' AS '.$alias.' ON '.$this->resolve($leftColumn)[0].' = '.$this->resolve($rightColumn)[0];
		} catch (\InvalidArgumentException $e) {
			unset($this->joinAliases[$alias]);

			throw $e;
		}

		return $this;
	}

	/**
	 * @param class-string|list<string>|null $columns
	 * @return array{table: string, mapping: ?EntityMapping, columns: ?list<string>}
	 * @throws \InvalidArgumentException
	 */
	private function joinDeclaration(string $table, string|array|null $columns): array
	{
		if ($table === $this->mapping->table) {
			return ['table' => $table, 'mapping' => $this->mapping, 'columns' => null];
		}
		if (is_string($columns)) {
			if (!class_exists($columns)) {
				throw new \InvalidArgumentException('Unknown entity class '.$columns.' for the join on '.$table);
			}
			try {
				return ['table' => $table, 'mapping' => $this->mappings->get($columns), 'columns' => null];
			} catch (\LogicException $e) {
				throw new \InvalidArgumentException('Join on '.$table.': '.$e->getMessage(), 0, $e);
			}
		}
		if ($columns === null) {
			return ['table' => $table, 'mapping' => null, 'columns' => null];
		}
		$names = [];
		foreach ($columns as $name) {
			if (!is_string($name) || preg_match(self::ALIAS, $name) !== 1 || self::isSecret($name)) {
				throw new \InvalidArgumentException('Invalid or secret declared column for the join on '.$table);
			}
			$names[] = $name;
		}

		return ['table' => $table, 'mapping' => null, 'columns' => $names];
	}

	/**
	 * @return array{string, ?ColumnMapping, bool} Column SQL, its mapping (main alias or mapped join), whether
	 *                                             it is declared (sortable)
	 * @throws \InvalidArgumentException
	 */
	private function resolve(string $column): array
	{
		if (preg_match(self::COLUMN, $column, $match) !== 1) {
			throw new \InvalidArgumentException('Invalid column "'.$column.'"');
		}
		if (self::isSecret($match[2])) {
			throw new \InvalidArgumentException('Secret column "'.$column.'" refused');
		}
		$alias = $match[1] !== '' ? $match[1] : $this->alias;
		if ($alias === $this->alias) {
			$mapping = $this->mapping;
			$declaredColumns = null;
		} elseif (isset($this->joinAliases[$alias])) {
			$mapping = $this->joinAliases[$alias]['mapping'];
			$declaredColumns = $this->joinAliases[$alias]['columns'];
		} else {
			throw new \InvalidArgumentException('Unknown alias in column "'.$column.'"');
		}
		if ($mapping !== null) {
			$mapped = $mapping->column($match[2]);
			if ($mapped === null) {
				throw new \InvalidArgumentException('Unknown column "'.$column.'" for '.$mapping->class);
			}

			return [$alias.'.'.$mapped->name, $mapped, true];
		}

		return [$alias.'.'.$match[2], null, $declaredColumns !== null && in_array($match[2], $declaredColumns, true)];
	}

	private static function isSecret(string $column): bool
	{
		return in_array(strtolower($column), MappingReader::SECRET_COLUMNS, true);
	}

	private function operator(mixed $operator): string
	{
		$normalized = is_string($operator) ? strtolower(trim((string) preg_replace('/\s+/', ' ', $operator))) : '';
		if (!in_array($normalized, self::OPERATORS, true)) {
			throw new \InvalidArgumentException('Unsupported SQL operator '.(is_string($operator) ? '"'.$operator.'"' : get_debug_type($operator)));
		}

		return $normalized;
	}

	/**
	 * The value as bound: cast by its mapped column (a LIKE pattern stays a string), as given on a joined column.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function bindable(?ColumnMapping $mappedColumn, string $operator, mixed $value, string $column): mixed
	{
		if (str_ends_with($operator, 'like')) {
			if (!is_scalar($value)) {
				throw new \InvalidArgumentException('LIKE pattern on '.$column.' is not a string');
			}

			return (string) $value;
		}
		if ($mappedColumn === null) {
			if ($value === null || is_scalar($value) || $value instanceof \BackedEnum || $value instanceof \DateTimeInterface) {
				return $value;
			}

			throw new \InvalidArgumentException('Unsupported value type '.get_debug_type($value).' on '.$column);
		}
		if (is_array($value) || (is_object($value) && !$value instanceof \BackedEnum && !$value instanceof \DateTimeInterface)) {
			throw new \InvalidArgumentException('Unsupported value type '.get_debug_type($value).' on '.$column);
		}
		try {
			return $this->casts->for($mappedColumn)->toDatabase($value);
		} catch (\ValueError | \TypeError $e) {
			throw new \InvalidArgumentException('Invalid value for '.$column.': '.$e->getMessage(), 0, $e);
		}
	}

	private function selectList(Grammar $grammar, QueryParameters $parameters): string
	{
		$columns = [];
		foreach ($this->columns as $column) {
			$columns[] = is_string($column)
				? $column
				: '('.Condition::bindRaw($column['expression'], $column['params'], $grammar->dialect(), $parameters).') AS '.$column['alias'];
		}
		if ($columns === []) {
			foreach ($this->mapping->columns as $mapped) {
				$columns[] = $this->alias.'.'.$mapped->name;
			}
		}

		return 'SELECT '.($this->distinct ? 'DISTINCT ' : '').implode(', ', $columns).$this->fragments($grammar, 'select');
	}

	/**
	 * FROM, joins and WHERE.
	 */
	private function body(Grammar $grammar, QueryParameters $parameters): string
	{
		$from = ' FROM @'.$this->mapping->table.' AS '.$this->alias.implode('', $this->joins).$this->fragments($grammar, 'from');

		return $from.$this->whereClause($grammar, $parameters);
	}

	private function whereClause(Grammar $grammar, QueryParameters $parameters): string
	{
		$parts = [];
		$entityScoped = $this->mapping->hasEntityColumn && $this->entityScoped;
		if ($entityScoped) {
			if ($this->entityScope === null) {
				throw new \LogicException($this->mapping->class.' has an entity column: its Query needs an EntityScope, or withoutEntityScope()');
			}
			$parts[] = $this->alias.'.entity IN ('.$parameters->named('entities', $this->entityScope->entitiesFor($this->mapping->element)).')';
		}
		$fragments = array_values(array_filter(
			array_map(static fn (string $fragment): string => trim($grammar->legacyFragment($fragment)), $this->legacyFragments['where']),
			static fn (string $fragment): bool => $fragment !== ''
		));
		$conditions = Condition::compileList($this->conditions, $grammar, $parameters, $this->caseInsensitive);
		if ($conditions !== '') {
			$parts[] = Condition::hasOr($this->conditions) && ($entityScoped || $fragments !== []) ? '('.$conditions.')' : $conditions;
		}
		if ($parts === [] && $fragments !== []) {
			$parts[] = '1 = 1';
		}
		$sql = implode(' AND ', $parts);
		foreach ($fragments as $fragment) {
			$sql .= preg_match('/^(AND|OR)\b/i', $fragment) === 1 ? ' '.$fragment : ' AND ('.$fragment.')';
		}

		return $sql === '' ? '' : ' WHERE '.$sql;
	}

	private function groupClause(): string
	{
		return $this->groups === [] ? '' : ' GROUP BY '.implode(', ', $this->groups);
	}

	/**
	 * @param 'select'|'from' $part
	 */
	private function fragments(Grammar $grammar, string $part): string
	{
		return implode('', array_map(static fn (string $fragment): string => $grammar->legacyFragment($fragment), $this->legacyFragments[$part]));
	}
}
