<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

use Dolibarr\Core\Database\DatabaseException;

/**
 * Generic repository of one entity class: a subclass names it ($entityClass) and adds its scopes as methods
 * returning a Query (spec §5.4). Queries run on the shared Orm (multicompany scope, extra fields, change
 * tracking); see Orm for what save() and delete() write.
 *
 * Every query, UPDATE and DELETE of an entity with an entity column is limited to the entity scope;
 * withoutEntityScope() gives a copy of the repository without it (login, multicompany administration), to be
 * asked for explicitly.
 *
 * @template T of object
 */
abstract class Repository
{
	/** @var class-string<T> */
	protected string $entityClass;

	private bool $entityScoped = true;

	public function __construct(protected readonly Orm $orm)
	{
	}

	/**
	 * @return Query<T>
	 * @throws \InvalidArgumentException Invalid alias
	 */
	public function query(string $alias = 't'): Query
	{
		$query = $this->orm->query($this->entityClass, $alias);

		return $this->entityScoped ? $query : $query->withoutEntityScope();
	}

	/**
	 * A copy of this repository whose queries, UPDATE and DELETE ignore the entity scope (Query::withoutEntityScope()
	 * on each of its queries). This repository keeps its scope.
	 */
	public function withoutEntityScope(): static
	{
		$copy = clone $this;
		$copy->entityScoped = false;

		return $copy;
	}

	/**
	 * The entity with this id, within the entity scope.
	 *
	 * @return T|null
	 */
	public function find(int $id): ?object
	{
		return $this->query()->where($this->mapping()->idProperty, $id)->first();
	}

	/**
	 * @return T
	 * @throws EntityNotFoundException
	 */
	public function findOrFail(int $id): object
	{
		return $this->find($id) ?? throw new EntityNotFoundException($this->entityClass, $id);
	}

	/**
	 * @param array<string, mixed>  $criteria Property or column => value (equality; a list is an IN)
	 * @param array<string, string> $orderBy  Property or column => ASC or DESC
	 * @return list<T>
	 * @throws \InvalidArgumentException Unknown column or invalid value
	 */
	public function findBy(array $criteria, array $orderBy = []): array
	{
		$query = $this->query();
		foreach ($criteria as $column => $value) {
			if (is_array($value)) {
				$query->whereIn($column, $value);
			} else {
				$query->where($column, $value);
			}
		}
		foreach ($orderBy as $column => $direction) {
			$query->orderBy($column, $direction);
		}

		return $query->get();
	}

	/**
	 * INSERT when the id is null, else UPDATE of the changed columns and extra fields (none: no query).
	 *
	 * @param T $entity
	 * @return T The saved entity: a new instance with its id after an INSERT
	 * @throws \InvalidArgumentException Another class, invalid extra fields
	 * @throws \LogicException           A partial entity (read with a select() lacking mapped columns)
	 * @throws EntityNotFoundException   UPDATE of a row missing or outside the entity scope
	 * @throws DatabaseException
	 */
	public function save(object $entity): object
	{
		return $this->orm->save($this->accepted($entity), $this->entityScoped);
	}

	/**
	 * A copy of the readonly $entity with $changes (property => value), tracked as the original: save() of the
	 * copy updates only what changed.
	 *
	 * @param T                    $entity
	 * @param array<string, mixed> $changes
	 * @return T
	 * @throws \InvalidArgumentException Another class, the id, unknown property or invalid value
	 */
	public function modify(object $entity, array $changes): object
	{
		return $this->orm->modify($this->accepted($entity), $changes);
	}

	/**
	 * @param T $entity
	 * @throws \InvalidArgumentException Another class, or an entity without id
	 * @throws EntityNotFoundException   No such row within the entity scope
	 * @throws DatabaseException
	 */
	public function delete(object $entity): void
	{
		$this->orm->delete($this->accepted($entity), $this->entityScoped);
	}

	protected function mapping(): EntityMapping
	{
		return $this->orm->mappings()->get($this->entityClass);
	}

	/**
	 * @param T $entity
	 * @return T
	 * @throws \InvalidArgumentException
	 */
	private function accepted(object $entity): object
	{
		if (!$entity instanceof $this->entityClass) {
			throw new \InvalidArgumentException(static::class.' handles '.$this->entityClass.', not '.$entity::class);
		}

		return $entity;
	}
}
