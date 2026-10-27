<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

use Dolibarr\Core\Database\DatabaseException;

/**
 * Loads the relations asked by Query::with(): one query per relation ('WHERE ... IN (:ids)' on the distinct ids,
 * plus one on the pivot table for a #[BelongsToMany]), whatever the number of entities. No query when there is no
 * entity, nor any key to look for. The target of a relation is read through its own query, so it respects its own
 * entity scope.
 *
 * Entities are readonly: each is rebuilt with its relation property filled (Orm::modify(), which keeps its
 * change tracking, so save() of a loaded entity still updates only the changed columns). A BelongsTo gives the
 * target or null (key null, or target missing or out of scope), the others a list ordered by the target's id.
 * Nested relations ('author.tags') are refused.
 */
final class RelationLoader
{
	/** @var array<class-string, \ReflectionClass<object>> */
	private array $reflections = [];

	public function __construct(private readonly Orm $orm)
	{
	}

	/**
	 * @param list<object>  $entities  Entities of $mapping
	 * @param list<string>  $relations Relation properties
	 * @return list<object> New instances, in the same order, with the relations filled
	 * @throws \InvalidArgumentException Unknown or nested relation
	 * @throws DatabaseException
	 */
	public function load(EntityMapping $mapping, array $entities, array $relations): array
	{
		foreach ($relations as $name) {
			if (str_contains($name, '.')) {
				throw new \InvalidArgumentException($mapping->class.': nested relation "'.$name.'" is not supported');
			}
			if (!isset($mapping->relations[$name])) {
				throw new \InvalidArgumentException($mapping->class.' has no relation "'.$name.'"');
			}
		}
		if ($entities === [] || $relations === []) {
			return $entities;
		}
		$changes = array_fill(0, count($entities), []);
		foreach ($relations as $name) {
			$relation = $mapping->relations[$name];
			$values = match ($relation->kind) {
				RelationMapping::BELONGS_TO => $this->belongsTo($mapping, $relation, $entities),
				RelationMapping::HAS_MANY => $this->hasMany($mapping, $relation, $entities),
				default => $this->belongsToMany($mapping, $relation, $entities),
			};
			foreach ($values as $position => $value) {
				$changes[$position][$name] = $value;
			}
		}
		$loaded = [];
		foreach ($entities as $position => $entity) {
			$loaded[] = $this->orm->modify($entity, $changes[$position]);
		}

		return $loaded;
	}

	/**
	 * @param list<object> $entities
	 * @return list<?object> The target of each entity
	 */
	private function belongsTo(EntityMapping $mapping, RelationMapping $relation, array $entities): array
	{
		$targetMapping = $this->orm->mappings()->get($relation->target);
		$keyProperty = $this->column($mapping, $relation->foreignKey)->property;
		$keys = [];
		foreach ($entities as $entity) {
			$keys[] = $this->value($entity, $keyProperty);
		}
		$targets = $this->targetsById($targetMapping, $keys);
		$values = [];
		foreach ($keys as $key) {
			$values[] = $key === null ? null : ($targets[(string) $key] ?? null);
		}

		return $values;
	}

	/**
	 * @param list<object> $entities
	 * @return list<list<object>>
	 */
	private function hasMany(EntityMapping $mapping, RelationMapping $relation, array $entities): array
	{
		$targetMapping = $this->orm->mappings()->get($relation->target);
		$foreignProperty = $this->column($targetMapping, $relation->foreignKey)->property;
		$ids = $this->ids($mapping, $entities);
		$byOwner = [];
		if ($ids !== []) {
			foreach ($this->targets($targetMapping, $relation->foreignKey, $ids) as $target) {
				$owner = $this->value($target, $foreignProperty);
				if ($owner !== null) {
					$byOwner[(string) $owner][] = $target;
				}
			}
		}
		$values = [];
		foreach ($entities as $entity) {
			$id = $this->value($entity, $mapping->idProperty);
			$values[] = $id === null ? [] : ($byOwner[(string) $id] ?? []);
		}

		return $values;
	}

	/**
	 * @param list<object> $entities
	 * @return list<list<object>>
	 */
	private function belongsToMany(EntityMapping $mapping, RelationMapping $relation, array $entities): array
	{
		$targetMapping = $this->orm->mappings()->get($relation->target);
		if ($relation->pivotTable === null || $relation->localKey === null) {
			throw new \LogicException($mapping->class.'::$'.$relation->property.': a BelongsToMany needs a pivot table and a local key');
		}
		$ids = $this->ids($mapping, $entities);
		$targetIdsByOwner = [];
		$targetIds = [];
		if ($ids !== []) {
			$rows = $this->orm->connection()->fetchAll(
				'SELECT '.$relation->localKey.' AS owner_id, '.$relation->foreignKey.' AS target_id FROM @'.$relation->pivotTable.' WHERE '.$relation->localKey.' IN (:ids)',
				['ids' => $ids]
			);
			foreach ($rows as $row) {
				$owner = $row['owner_id'];
				$target = $row['target_id'];
				if (is_scalar($owner) && is_scalar($target)) {
					$targetIdsByOwner[(string) $owner][(string) $target] = true;
					$targetIds[(string) $target] = $target;
				}
			}
		}
		$targets = $this->targetsById($targetMapping, array_values($targetIds));
		$values = [];
		foreach ($entities as $entity) {
			$id = $this->value($entity, $mapping->idProperty);
			$list = [];
			foreach (array_keys($targetIdsByOwner[(string) $id] ?? []) as $targetId) {
				if (isset($targets[(string) $targetId])) {
					$list[] = $targets[(string) $targetId];
				}
			}
			$values[] = $list;
		}

		return $values;
	}

	/**
	 * @param list<int|float|string|null> $keys
	 * @return array<string, object> Target by id
	 */
	private function targetsById(EntityMapping $targetMapping, array $keys): array
	{
		$distinct = [];
		foreach ($keys as $key) {
			if ($key !== null) {
				$distinct[(string) $key] = $key;
			}
		}
		if ($distinct === []) {
			return [];
		}
		$byId = [];
		foreach ($this->targets($targetMapping, $targetMapping->idProperty, array_values($distinct)) as $target) {
			$id = $this->value($target, $targetMapping->idProperty);
			if ($id !== null) {
				$byId[(string) $id] = $target;
			}
		}

		return $byId;
	}

	/**
	 * @param list<int|float|string> $values
	 * @return list<object>
	 */
	private function targets(EntityMapping $targetMapping, string $column, array $values): array
	{
		return $this->orm->query($targetMapping->class)
			->whereIn($column, $values)
			->orderBy($targetMapping->idProperty)
			->get();
	}

	/**
	 * Distinct non null ids of the entities.
	 *
	 * @param list<object> $entities
	 * @return list<int|float|string>
	 */
	private function ids(EntityMapping $mapping, array $entities): array
	{
		$ids = [];
		foreach ($entities as $entity) {
			$id = $this->value($entity, $mapping->idProperty);
			if ($id !== null) {
				$ids[(string) $id] = $id;
			}
		}

		return array_values($ids);
	}

	private function column(EntityMapping $mapping, string $name): ColumnMapping
	{
		return $mapping->column($name) ?? throw new \LogicException($mapping->class.': relation key "'.$name.'" is not a mapped column');
	}

	private function value(object $entity, string $property): int|float|string|null
	{
		$reflection = $this->reflections[$entity::class] ??= new \ReflectionClass($entity);
		$value = $reflection->getProperty($property)->getValue($entity);

		return is_scalar($value) ? $value : null;
	}
}
