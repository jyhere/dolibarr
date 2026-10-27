<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

use Dolibarr\Core\Orm\Cast\CastRegistry;

/**
 * Builds entities from rows through their constructor (named arguments) and extracts their column values.
 */
final class Hydrator
{
	/** @var array<class-string, \ReflectionClass<object>> */
	private array $reflections = [];

	public function __construct(private readonly CastRegistry $casts = new CastRegistry())
	{
	}

	/**
	 * @param array<string, mixed> $row            Column name => value as the driver gives it
	 * @param array<string, mixed> $propertyValues Constructor parameter that is not a column => value, given as is:
	 *                                             relations, extra fields, computed values (User::$hasApiKey,
	 *                                             read from a secret column the mapping never holds); the
	 *                                             parameters not given receive their default value
	 * @throws \LogicException When the row lacks a column without default value, or a property value names a
	 *                         column or no constructor parameter
	 */
	public function hydrate(EntityMapping $mapping, array $row, array $propertyValues = []): object
	{
		$arguments = [];
		foreach ($mapping->columns as $property => $column) {
			if (array_key_exists($column->name, $row)) {
				$arguments[$property] = $this->casts->for($column)->fromDatabase($row[$column->name]);
			} elseif ($this->parameter($mapping->class, $property)?->isDefaultValueAvailable() !== true) {
				throw new \LogicException($mapping->class.': the row has no column '.$column->name.' for $'.$property);
			}
		}
		foreach ($propertyValues as $property => $value) {
			if (isset($mapping->columns[$property])) {
				throw new \LogicException($mapping->class.'::$'.$property.' is a column: its value comes from the row');
			}
			if ($this->parameter($mapping->class, $property) === null) {
				throw new \LogicException($mapping->class.': no constructor parameter $'.$property);
			}
			$arguments[$property] = $value;
		}

		return $this->reflection($mapping->class)->newInstanceArgs($arguments);
	}

	/**
	 * @return array<string, int|float|string|null> Column name => value to bind
	 */
	public function extract(EntityMapping $mapping, object $entity): array
	{
		$reflection = $this->reflection($mapping->class);
		$values = [];
		foreach ($mapping->columns as $property => $column) {
			$values[$column->name] = $this->casts->for($column)->toDatabase($reflection->getProperty($property)->getValue($entity));
		}

		return $values;
	}

	/**
	 * A column value as the driver gives it, in the form extract() gives it ('1.50' and 1.5 are both 1.5).
	 */
	public function normalize(ColumnMapping $column, mixed $databaseValue): int|float|string|null
	{
		$cast = $this->casts->for($column);

		return $cast->toDatabase($cast->fromDatabase($databaseValue));
	}

	/**
	 * @param class-string $class
	 * @return \ReflectionClass<object>
	 */
	private function reflection(string $class): \ReflectionClass
	{
		return $this->reflections[$class] ??= new \ReflectionClass($class);
	}

	/**
	 * @param class-string $class
	 */
	private function parameter(string $class, string $name): ?\ReflectionParameter
	{
		foreach ($this->reflection($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
			if ($parameter->getName() === $name) {
				return $parameter;
			}
		}

		return null;
	}
}
