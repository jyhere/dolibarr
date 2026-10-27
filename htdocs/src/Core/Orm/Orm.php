<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;

/**
 * The ORM of a request, shared by the repositories (one Snapshots per request): builds their queries, runs them
 * (QueryExecutor) and writes their entities.
 *
 * Reads: an entity with #[ExtraFields] gets 'LEFT JOIN <table>_extrafields AS ef ON ef.fk_object = t.rowid' on
 * every query, so 'ef.<name>' columns can be filtered and sorted on (and the legacy extrafields search SQL finds
 * its 'ef' alias), and its extra fields selected as 'ef.<name> AS ef__<name>' on any select list. Their names are
 * those of a row of the table, read once per request (rowid, fk_object, tms, import_key, secret and non lower case
 * identifier columns left out); an empty table has no names yet, and is read again by the next query. The
 * property receives the raw values as strings ([] when the object has no extra fields row).
 *
 * Writes (save(), delete()) run in Connection::transactional(), so inside the transaction of the legacy code or
 * of the service when there is one. No tms, datec or fk_user_* handling (spec §5.5), no event: the service
 * does both. UPDATE and DELETE target the id and, for a table with an entity column, the entity scope
 * ('AND entity IN (:entities)', unless $entityScoped is false: Repository::withoutEntityScope()); a row
 * missing or outside the scope throws EntityNotFoundException and nothing is written (no extra fields row
 * either). INSERT leaves the entity column to the service.
 *
 * Entities are readonly: modify() builds a modified copy that keeps the snapshot of the original, so save()
 * updates only what changed. An entity read with a select() lacking mapped columns is partial: save() refuses it
 * (LogicException) rather than writing its absent columns as their constructor defaults.
 */
final class Orm implements QueryExecutor
{
	/** Alias of the extra fields table in every query of an entity with #[ExtraFields]. */
	public const EXTRA_FIELDS_ALIAS = 'ef';

	/** Prefix of the selected extra fields columns ('ef.color AS ef__color'). */
	private const EXTRA_FIELD_PREFIX = 'ef__';

	/** fk_object of the extra fields row, selected to know whether the row exists. */
	private const EXTRA_FIELDS_MARKER = 'ef__fk_object';

	/** Columns of a <table>_extrafields that are not extra fields. */
	private const EXTRA_FIELDS_TECHNICAL = ['rowid', 'fk_object', 'tms', 'import_key'];

	private const EXTRA_FIELD_NAME = '/^[a-z_][a-z0-9_]*$/D';

	private ?Grammar $grammar = null;

	/** @var array<string, list<string>> Extra fields table => its extra field columns (cached once found) */
	private array $extraFieldColumns = [];

	/**
	 * Extra fields as read or saved: whether the row exists, and the values compared by save().
	 *
	 * @var \WeakMap<object, array{exists: bool, values: array<string, ?string>}>
	 */
	private \WeakMap $extraFields;

	/** @var \WeakMap<object, bool> Entities read from a row lacking mapped columns */
	private \WeakMap $partial;

	/** @var array<class-string, \ReflectionClass<object>> */
	private array $reflections = [];

	public function __construct(
		private readonly Connection $connection,
		private readonly MappingRegistry $mappings,
		private readonly EntityScope $scope,
		private readonly Hydrator $hydrator,
		private readonly Snapshots $snapshots
	) {
		// PHPStan 2.1 refuses new WeakMap() for these TValue (see Snapshots).
		$this->extraFields = new \WeakMap(); // @phpstan-ignore assign.propertyType
		$this->partial = new \WeakMap(); // @phpstan-ignore assign.propertyType
	}

	public function connection(): Connection
	{
		return $this->connection;
	}

	public function mappings(): MappingRegistry
	{
		return $this->mappings;
	}

	public function entityScope(): EntityScope
	{
		return $this->scope;
	}

	public function grammar(): Grammar
	{
		return $this->grammar ??= $this->connection->dialect() === 'pgsql'
			? PgsqlGrammar::forLegacy($this->connection->legacy())
			: new MysqlGrammar();
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return Query<T>
	 * @throws \InvalidArgumentException Invalid alias, or 'ef' for an entity with extra fields
	 * @throws \LogicException           When the class is not a valid entity mapping
	 * @throws DatabaseException         When the extra fields table cannot be read
	 */
	public function query(string $class, string $alias = 't'): Query
	{
		$mapping = $this->mappings->get($class);
		/** @var Query<T> $query */
		$query = new Query($mapping, $this->grammar(), $this->scope, $this, $alias, mappings: $this->mappings);
		if ($mapping->extraFieldsProperty === null) {
			return $query;
		}
		if ($alias === self::EXTRA_FIELDS_ALIAS) {
			throw new \InvalidArgumentException('The alias "'.self::EXTRA_FIELDS_ALIAS.'" is the extra fields one for '.$class);
		}
		$columns = $this->extraFieldColumns($mapping);
		$query->leftJoin($mapping->table.'_extrafields', self::EXTRA_FIELDS_ALIAS, self::EXTRA_FIELDS_ALIAS.'.fk_object', $alias.'.'.$mapping->id()->name, $columns);
		$selected = [self::EXTRA_FIELDS_ALIAS.'.fk_object AS '.self::EXTRA_FIELDS_MARKER];
		foreach ($columns as $column) {
			$selected[] = self::EXTRA_FIELDS_ALIAS.'.'.$column.' AS '.self::EXTRA_FIELD_PREFIX.$column;
		}

		return $query->alwaysSelect(...$selected);
	}

	/**
	 * @template T of object
	 * @param Query<T> $query
	 * @return list<T>
	 * @throws \LogicException When the query asks for relations (with())
	 */
	public function get(Query $query): array
	{
		$this->refuseRelations($query);
		$entities = [];
		foreach ($this->rows($query) as $row) {
			/** @var T $entity */
			$entity = $this->entity($query->mapping(), $row);
			$entities[] = $entity;
		}

		return $entities;
	}

	/**
	 * The first entity, the query itself left unchanged (its limit applies to a copy).
	 *
	 * @template T of object
	 * @param Query<T> $query
	 * @return T|null
	 */
	public function first(Query $query): ?object
	{
		return $this->get((clone $query)->limit(1))[0] ?? null;
	}

	/**
	 * @param Query<object> $query
	 */
	public function count(Query $query): int
	{
		$compiled = $query->compileCount();

		return (int) $this->connection->fetchValue($compiled->sql, $compiled->params);
	}

	/**
	 * @param Query<object> $query
	 */
	public function exists(Query $query): bool
	{
		return $this->rows((clone $query)->limit(1)) !== [];
	}

	/**
	 * @template T of object
	 * @param Query<T> $query
	 * @return Page<T>
	 */
	public function paginate(Query $query, int $page, int $perPage): Page
	{
		if ($page < 1 || $perPage < 1) {
			throw new \InvalidArgumentException('Invalid page '.$page.' of '.$perPage.' items');
		}
		$this->refuseRelations($query);
		$total = $this->count($query);
		$items = $this->get((clone $query)->limit($perPage)->offset(($page - 1) * $perPage));

		return new Page($items, $total, $page, $perPage);
	}

	/**
	 * Raw rows (driver values), for lists with computed columns; extra fields come as 'ef__<name>'.
	 *
	 * @param Query<object> $query
	 * @return list<array<string, mixed>>
	 */
	public function rows(Query $query): array
	{
		$compiled = $query->compile();

		return $this->connection->fetchAll($compiled->sql, $compiled->params);
	}

	/**
	 * INSERT (null id: the non null columns, the extra fields row when one is not null) or UPDATE of what
	 * changed since the entity was read (columns, then the extra fields row, inserted when missing), in one
	 * transaction. Nothing runs, not even BEGIN, when nothing changed.
	 *
	 * An entity built by the code with an id but never read (no snapshot) has every column updated, and its
	 * extra fields row is looked up.
	 *
	 * The UPDATE matching no row is told apart from an UPDATE setting identical values (MySQL counts changed
	 * rows, not matched ones, and the shared link has no CLIENT_FOUND_ROWS) by a SELECT of the row with the same
	 * WHERE; so is an update of the extra fields alone, which has no main UPDATE to check the row.
	 *
	 * @template T of object
	 * @param T    $entity
	 * @param bool $entityScoped False: UPDATE without the entity scope (Repository::withoutEntityScope())
	 * @return T The new entity with its id after an INSERT, $entity after an UPDATE
	 * @throws \LogicException           A partial entity, or a class that is not an entity
	 * The entity column written (INSERT, or UPDATE when it changed) must be one of the scope's entities, unless
	 * $entityScoped is false: a row is never moved to, or created in, an entity the scope cannot read.
	 *
	 * No statement that writes data runs before the row is known to be in scope: inside an enclosing (service or
	 * legacy) transaction, DoliDB::rollback() only lowers the nesting level and undoes nothing.
	 *
	 * @throws \InvalidArgumentException Invalid extra field name or value, entity column outside the scope
	 * @throws EntityNotFoundException   UPDATE of a row missing or outside the entity scope (nothing written)
	 * @throws DatabaseException         The transaction is rolled back
	 */
	public function save(object $entity, bool $entityScoped = true): object
	{
		$mapping = $this->mappings->get($entity::class);
		if ($this->partial->offsetExists($entity)) {
			throw new \LogicException($entity::class.': a partial entity (read with a select() lacking mapped columns) cannot be saved: its absent columns would be written as their defaults');
		}
		$extraFields = $this->extraFieldValues($mapping, $entity);
		$values = $this->hydrator->extract($mapping, $entity);
		$idColumn = $mapping->id()->name;
		if ($values[$idColumn] === null) {
			return $this->insert($mapping, $entity, $values, $extraFields, $entityScoped);
		}
		$this->update($mapping, $entity, $values, $extraFields, $entityScoped);

		return $entity;
	}

	/**
	 * A copy of the readonly $entity with $changes (constructor parameter => value), which keeps the snapshot
	 * (and partial state) of $entity: save() of the copy updates only what differs from what was read.
	 *
	 * @template T of object
	 * @param T                    $entity
	 * @param array<string, mixed> $changes
	 * @return T
	 * @throws \InvalidArgumentException Unknown parameter or invalid value
	 */
	public function modify(object $entity, array $changes): object
	{
		$idProperty = $this->mappings->get($entity::class)->idProperty;
		if (array_key_exists($idProperty, $changes)) {
			throw new \InvalidArgumentException($entity::class.': modify() cannot change the id $'.$idProperty);
		}
		$reflection = $this->reflection($entity::class);
		$arguments = [];
		foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
			$name = $parameter->getName();
			if (array_key_exists($name, $changes)) {
				$arguments[$name] = $changes[$name];
				unset($changes[$name]);
			} elseif ($reflection->hasProperty($name) && $reflection->getProperty($name)->isInitialized($entity)) {
				$arguments[$name] = $reflection->getProperty($name)->getValue($entity);
			}
		}
		if ($changes !== []) {
			throw new \InvalidArgumentException($entity::class.': no constructor parameter $'.implode(', $', array_keys($changes)));
		}
		try {
			/** @var T $copy */
			$copy = $reflection->newInstanceArgs($arguments);
		} catch (\TypeError | \ArgumentCountError $e) {
			throw new \InvalidArgumentException($entity::class.': '.$e->getMessage(), 0, $e);
		}
		$this->snapshots->copy($entity, $copy);
		if ($this->extraFields->offsetExists($entity)) {
			$this->extraFields->offsetSet($copy, $this->extraFields->offsetGet($entity));
		}
		if ($this->partial->offsetExists($entity)) {
			$this->partial->offsetSet($copy, true);
		}

		return $copy;
	}

	/**
	 * Deletes the extra fields row, then the row, in one transaction, once the row is known to exist within the
	 * entity scope (unless $entityScoped is false): a SELECT with the scoped WHERE comes first for an entity with
	 * extra fields, so a refused delete has deleted nothing even inside an enclosing transaction (whose nested
	 * rollback undoes nothing). Without extra fields the scoped DELETE is the first statement.
	 *
	 * @throws \InvalidArgumentException An entity without id
	 * @throws \LogicException           A class that is not an entity
	 * @throws EntityNotFoundException   No such row within the scope
	 * @throws DatabaseException         The transaction is rolled back
	 */
	public function delete(object $entity, bool $entityScoped = true): void
	{
		$mapping = $this->mappings->get($entity::class);
		$idColumn = $mapping->id()->name;
		$id = $this->hydrator->extract($mapping, $entity)[$idColumn];
		if ($id === null) {
			throw new \InvalidArgumentException($entity::class.': an entity without id cannot be deleted');
		}
		[$where, $params] = $this->rowCondition($mapping, $id, $entityScoped);
		$this->connection->transactional(function () use ($mapping, $id, $where, $params): void {
			if ($mapping->extraFieldsProperty !== null) {
				$this->requireRow($mapping, $id, $where, $params);
				$this->connection->execute('DELETE FROM @'.$mapping->table.'_extrafields WHERE fk_object = :id', ['id' => $id]);
			}
			if ($this->connection->execute('DELETE FROM @'.$mapping->table.' WHERE '.$where, $params) === 0) {
				throw new EntityNotFoundException($mapping->class, (int) $id);
			}
		});
		$this->snapshots->forget($entity);
		$this->extraFields->offsetUnset($entity);
		$this->partial->offsetUnset($entity);
	}

	/**
	 * @param array<string, int|float|string|null>       $values      Column values (extract())
	 * @param array<string, int|float|string|bool|null>|null $extraFields
	 */
	private function insert(EntityMapping $mapping, object $entity, array $values, ?array $extraFields, bool $entityScoped): object
	{
		$this->refuseEntityOutsideScope($mapping, $values, $entityScoped);
		$idColumn = $mapping->id()->name;
		$columns = array_filter($values, static fn (mixed $value): bool => $value !== null);
		unset($columns[$idColumn]);
		$extraColumns = array_filter($extraFields ?? [], static fn (mixed $value): bool => $value !== null);

		$id = $this->connection->transactional(function () use ($mapping, $idColumn, $columns, $extraColumns): int {
			$this->insertRow($mapping->table, $columns);
			$id = $this->connection->lastInsertId($mapping->table, $idColumn);
			if ($extraColumns !== []) {
				$this->insertRow($mapping->table.'_extrafields', ['fk_object' => $id] + $extraColumns);
			}

			return $id;
		});

		$values[$idColumn] = $id;
		$carried = $this->carriedValues($mapping, $entity);
		$saved = $this->hydrator->hydrate($mapping, $values, $carried);
		$this->snapshots->remember($saved, $values);
		if ($extraFields !== null) {
			$this->extraFields->offsetSet($saved, ['exists' => $extraColumns !== [], 'values' => self::normalizeExtraFields($extraFields)]);
		}

		return $saved;
	}

	/**
	 * @param array<string, int|float|string|null>           $values
	 * @param array<string, int|float|string|bool|null>|null $extraFields
	 */
	private function update(EntityMapping $mapping, object $entity, array $values, ?array $extraFields, bool $entityScoped): void
	{
		$idColumn = $mapping->id()->name;
		$id = $values[$idColumn] ?? throw new \LogicException('update() of an entity without id');
		$changes = $this->snapshots->changes($mapping, $entity);
		unset($changes[$idColumn]);
		$this->refuseEntityOutsideScope($mapping, $changes, $entityScoped);

		$extraChanges = [];
		$extraExists = null;
		if ($extraFields !== null) {
			$state = $this->extraFields->offsetExists($entity) ? $this->extraFields->offsetGet($entity) : null;
			$current = self::normalizeExtraFields($extraFields);
			foreach ($extraFields as $name => $value) {
				if ($state === null || !array_key_exists($name, $state['values']) || $state['values'][$name] !== $current[$name]) {
					$extraChanges[$name] = $value;
				}
			}
			$extraExists = $state['exists'] ?? null;
		}
		if ($changes === [] && $extraChanges === []) {
			return;
		}

		[$where, $whereParams] = $this->rowCondition($mapping, $id, $entityScoped);
		$extraExists = $this->connection->transactional(function () use ($mapping, $id, $where, $whereParams, $changes, $extraFields, $extraChanges, $extraExists): ?bool {
			$updated = 0;
			if ($changes !== []) {
				[$assignments, $params] = self::assignments($changes);
				$updated = $this->connection->execute('UPDATE @'.$mapping->table.' SET '.$assignments.' WHERE '.$where, $params + $whereParams);
			}
			if ($updated === 0) {
				$this->requireRow($mapping, $id, $where, $whereParams);
			}
			if ($extraChanges === []) {
				return $extraExists;
			}
			$table = $mapping->table.'_extrafields';
			$extraExists ??= (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @'.$table.' WHERE fk_object = :id', ['id' => $id]) > 0;
			if ($extraExists) {
				[$assignments, $params] = self::assignments($extraChanges);
				$params['id'] = $id;
				$this->connection->execute('UPDATE @'.$table.' SET '.$assignments.' WHERE fk_object = :id', $params);
			} else {
				$this->insertRow($table, ['fk_object' => $id] + array_filter($extraFields ?? [], static fn (mixed $value): bool => $value !== null));
			}

			return true;
		});

		$this->snapshots->remember($entity, $values);
		if ($extraFields !== null) {
			$this->extraFields->offsetSet($entity, ['exists' => $extraExists === true, 'values' => self::normalizeExtraFields($extraFields)]);
		}
	}

	/**
	 * @param array<string, mixed> $params
	 * @throws EntityNotFoundException
	 */
	private function requireRow(EntityMapping $mapping, int|float|string $id, string $where, array $params): void
	{
		if ($this->connection->fetchOne('SELECT 1 AS found FROM @'.$mapping->table.' WHERE '.$where, $params) === null) {
			throw new EntityNotFoundException($mapping->class, (int) $id);
		}
	}

	/**
	 * @param array<string, int|float|string|null> $columns Columns about to be written
	 * @throws \InvalidArgumentException When the entity column is outside the scope
	 */
	private function refuseEntityOutsideScope(EntityMapping $mapping, array $columns, bool $entityScoped): void
	{
		if (!$entityScoped || !$mapping->hasEntityColumn || !isset($columns['entity'])) {
			return;
		}
		$entities = $this->scope->entitiesFor($mapping->element);
		if (!in_array((int) $columns['entity'], $entities, true)) {
			throw new \InvalidArgumentException($mapping->class.': entity '.$columns['entity'].' is outside the entity scope ('.implode(', ', $entities).'): use withoutEntityScope()');
		}
	}

	/**
	 * WHERE of the row of an id: 'rowid = :id', and 'AND entity IN (:entities)' for a scoped table with an entity
	 * column. Its parameter names do not clash with assignments() ones (:v1…).
	 *
	 * @return array{string, array<string, mixed>}
	 */
	private function rowCondition(EntityMapping $mapping, int|float|string $id, bool $entityScoped): array
	{
		$where = $mapping->id()->name.' = :id';
		$params = ['id' => $id];
		if ($entityScoped && $mapping->hasEntityColumn) {
			$where .= ' AND entity IN (:entities)';
			$params['entities'] = $this->scope->entitiesFor($mapping->element);
		}

		return [$where, $params];
	}

	/**
	 * @param array<string, mixed> $columns Column (from the mapping or a checked extra field name) => value
	 */
	private function insertRow(string $table, array $columns): void
	{
		if ($columns === []) {
			$this->connection->execute('INSERT INTO @'.$table.($this->connection->dialect() === 'pgsql' ? ' DEFAULT VALUES' : ' () VALUES ()'));

			return;
		}
		$placeholders = [];
		$params = [];
		$position = 0;
		foreach ($columns as $value) {
			$position++;
			$placeholders[] = ':v'.$position;
			$params['v'.$position] = $value;
		}
		$this->connection->execute('INSERT INTO @'.$table.' ('.implode(', ', array_keys($columns)).') VALUES ('.implode(', ', $placeholders).')', $params);
	}

	/**
	 * @param array<string, mixed> $columns
	 * @return array{string, array<string, mixed>} 'a = :v1, b = :v2' and its parameters
	 */
	private static function assignments(array $columns): array
	{
		$assignments = [];
		$params = [];
		$position = 0;
		foreach ($columns as $column => $value) {
			$position++;
			$assignments[] = $column.' = :v'.$position;
			$params['v'.$position] = $value;
		}

		return [implode(', ', $assignments), $params];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function entity(EntityMapping $mapping, array $row): object
	{
		$propertyValues = [];
		$extraState = null;
		if ($mapping->extraFieldsProperty !== null) {
			$exists = ($row[self::EXTRA_FIELDS_MARKER] ?? null) !== null;
			$extraFields = [];
			if ($exists) {
				foreach ($row as $key => $value) {
					if ($key !== self::EXTRA_FIELDS_MARKER && str_starts_with($key, self::EXTRA_FIELD_PREFIX)) {
						$extraFields[substr($key, strlen(self::EXTRA_FIELD_PREFIX))] = self::rawExtraField($value);
					}
				}
			}
			$propertyValues[$mapping->extraFieldsProperty] = $extraFields;
			$extraState = ['exists' => $exists, 'values' => $extraFields];
		}
		$entity = $this->hydrator->hydrate($mapping, $row, $propertyValues);

		$columnValues = [];
		$partial = false;
		foreach ($mapping->columns as $column) {
			if (array_key_exists($column->name, $row)) {
				$columnValues[$column->name] = $row[$column->name];
			} else {
				$partial = true;
			}
		}
		$this->snapshots->remember($entity, $columnValues);
		if ($extraState !== null) {
			$this->extraFields->offsetSet($entity, $extraState);
		}
		if ($partial) {
			$this->partial->offsetSet($entity, true);
		}

		return $entity;
	}

	/**
	 * Extra field columns of the entity's <table>_extrafields, from one of its rows; none while it is empty.
	 *
	 * @return list<string>
	 * @throws DatabaseException
	 */
	private function extraFieldColumns(EntityMapping $mapping): array
	{
		$table = $mapping->table.'_extrafields';
		if (isset($this->extraFieldColumns[$table])) {
			return $this->extraFieldColumns[$table];
		}
		$row = $this->connection->fetchOne('SELECT * FROM @'.$table.$this->grammar()->limit(1, null));
		if ($row === null) {
			return [];
		}
		$columns = [];
		foreach (array_keys($row) as $column) {
			if (self::isExtraFieldName($column)) {
				$columns[] = $column;
			}
		}

		return $this->extraFieldColumns[$table] = $columns;
	}

	/**
	 * The extra fields property, checked: names that can be columns, scalar values.
	 *
	 * @return array<string, int|float|string|bool|null>|null Null when the entity has no #[ExtraFields]
	 * @throws \InvalidArgumentException
	 */
	private function extraFieldValues(EntityMapping $mapping, object $entity): ?array
	{
		if ($mapping->extraFieldsProperty === null) {
			return null;
		}
		$property = $this->reflection($mapping->class)->getProperty($mapping->extraFieldsProperty);
		$values = $property->isInitialized($entity) ? $property->getValue($entity) : [];
		if (!is_array($values)) {
			throw new \InvalidArgumentException($mapping->class.'::$'.$mapping->extraFieldsProperty.' is not an array');
		}
		$checked = [];
		foreach ($values as $name => $value) {
			if (!is_string($name) || !self::isExtraFieldName($name)) {
				throw new \InvalidArgumentException($mapping->class.': invalid extra field name "'.$name.'"');
			}
			if ($value !== null && !is_scalar($value)) {
				throw new \InvalidArgumentException($mapping->class.': the extra field '.$name.' is not a scalar ('.get_debug_type($value).')');
			}
			$checked[$name] = $value;
		}

		return $checked;
	}

	private static function isExtraFieldName(string $name): bool
	{
		return preg_match(self::EXTRA_FIELD_NAME, $name) === 1
			&& !in_array($name, self::EXTRA_FIELDS_TECHNICAL, true)
			&& !in_array($name, MappingReader::SECRET_COLUMNS, true);
	}

	/**
	 * @param array<string, int|float|string|bool|null> $values
	 * @return array<string, ?string>
	 */
	private static function normalizeExtraFields(array $values): array
	{
		return array_map(self::rawExtraField(...), $values);
	}

	/**
	 * A value as the extra fields property holds it once read: a string, or null.
	 */
	private static function rawExtraField(mixed $value): ?string
	{
		return match (true) {
			$value === null => null,
			is_bool($value) => $value ? '1' : '0',
			is_scalar($value) => (string) $value,
			default => null,
		};
	}

	/**
	 * Values of the constructor parameters that are not columns (relations, extra fields, computed values), to
	 * build the saved copy of an entity.
	 *
	 * @return array<string, mixed>
	 */
	private function carriedValues(EntityMapping $mapping, object $entity): array
	{
		$reflection = $this->reflection($mapping->class);
		$values = [];
		foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
			$name = $parameter->getName();
			if (!isset($mapping->columns[$name]) && $reflection->hasProperty($name) && $reflection->getProperty($name)->isInitialized($entity)) {
				$values[$name] = $reflection->getProperty($name)->getValue($entity);
			}
		}

		return $values;
	}

	/**
	 * @param Query<object> $query
	 * @throws \LogicException
	 */
	private function refuseRelations(Query $query): void
	{
		if ($query->relations() !== []) {
			throw new \LogicException('with('.implode(', ', $query->relations()).'): relations are loaded in Task 7');
		}
	}

	/**
	 * @param class-string $class
	 * @return \ReflectionClass<object>
	 */
	private function reflection(string $class): \ReflectionClass
	{
		return $this->reflections[$class] ??= new \ReflectionClass($class);
	}
}
