<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * Column values of the entities as read, to update only what changed. An entity without snapshot is new.
 * Held weakly: an entity no longer used drops its snapshot.
 */
final class Snapshots
{
	/** @var \WeakMap<object, array<string, int|float|string|bool|null>> */
	private \WeakMap $snapshots;

	public function __construct(private readonly Hydrator $hydrator)
	{
		// PHPStan 2.1 refuses new WeakMap() for a TValue containing null ("does not accept" the very same type).
		$this->snapshots = new \WeakMap(); // @phpstan-ignore assign.propertyType
	}

	/**
	 * @param array<string, mixed> $databaseValues Column name => value, as the driver gave it or as extract() gives it;
	 *                                             a non scalar value is not kept (its column counts as changed)
	 */
	public function remember(object $entity, array $databaseValues): void
	{
		// offsetSet()/offsetUnset() rather than [] =: PHPStan reads an offset write as a property reassignment.
		$this->snapshots->offsetSet($entity, array_filter($databaseValues, static fn (mixed $value): bool => $value === null || is_scalar($value)));
	}

	public function has(object $entity): bool
	{
		return $this->snapshots->offsetExists($entity);
	}

	/**
	 * Values compared in their bound form, so a float or a date read and written back unchanged is no change.
	 *
	 * For a new entity (no snapshot) every column is returned, the id column included with its value (null
	 * before INSERT): the repository drops it from the INSERT.
	 *
	 * @return array<string, int|float|string|null> Changed columns => value to bind; every column for a new entity
	 */
	public function changes(EntityMapping $mapping, object $entity): array
	{
		$current = $this->hydrator->extract($mapping, $entity);
		if (!$this->snapshots->offsetExists($entity)) {
			return $current;
		}
		$snapshot = $this->snapshots->offsetGet($entity);
		$changes = [];
		foreach ($mapping->columns as $column) {
			$value = $current[$column->name];
			if (!array_key_exists($column->name, $snapshot) || $this->hydrator->normalize($column, $snapshot[$column->name]) !== $value) {
				$changes[$column->name] = $value;
			}
		}

		return $changes;
	}

	public function forget(object $entity): void
	{
		$this->snapshots->offsetUnset($entity);
	}
}
