<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * Mapping of an entity class to its table, read from its attributes (MappingReader).
 *
 * @phpstan-import-type ColumnArray from ColumnMapping
 * @phpstan-import-type RelationArray from RelationMapping
 * @phpstan-type EntityArray array{class: string, table: string, element: string, idProperty: string, columns: list<ColumnArray>, relations: list<RelationArray>, extraFieldsProperty: ?string}
 */
final class EntityMapping
{
	public readonly bool $hasEntityColumn;

	/** @var array<string, ColumnMapping> Column name => column */
	private readonly array $byColumnName;

	/**
	 * @param class-string                  $class
	 * @param string                        $table     Table without prefix
	 * @param string                        $element   Element given to getEntity()
	 * @param array<string, ColumnMapping>  $columns   By property
	 * @param array<string, RelationMapping> $relations By property
	 * @throws \LogicException When the id property is not a column
	 */
	public function __construct(
		public readonly string $class,
		public readonly string $table,
		public readonly string $element,
		public readonly string $idProperty,
		public readonly array $columns,
		public readonly array $relations,
		public readonly ?string $extraFieldsProperty
	) {
		if (!isset($columns[$idProperty])) {
			throw new \LogicException($class.': the #[Id] property '.$idProperty.' is not a column');
		}
		$byColumnName = [];
		foreach ($columns as $column) {
			$byColumnName[$column->name] = $column;
		}
		$this->byColumnName = $byColumnName;
		$this->hasEntityColumn = isset($byColumnName['entity']);
	}

	/**
	 * The column of a property, or else of a column name; null when neither is mapped (the whitelist used for
	 * names coming from a request).
	 */
	public function column(string $propertyOrColumn): ?ColumnMapping
	{
		return $this->columns[$propertyOrColumn] ?? $this->byColumnName[$propertyOrColumn] ?? null;
	}

	public function id(): ColumnMapping
	{
		return $this->columns[$this->idProperty];
	}

	/**
	 * @return EntityArray
	 */
	public function toArray(): array
	{
		return [
			'class' => $this->class,
			'table' => $this->table,
			'element' => $this->element,
			'idProperty' => $this->idProperty,
			'columns' => array_values(array_map(static fn (ColumnMapping $column): array => $column->toArray(), $this->columns)),
			'relations' => array_values(array_map(static fn (RelationMapping $relation): array => $relation->toArray(), $this->relations)),
			'extraFieldsProperty' => $this->extraFieldsProperty,
		];
	}

	/**
	 * @param array<mixed> $data
	 * @throws \UnexpectedValueException When the array is not an EntityArray
	 */
	public static function fromArray(array $data): self
	{
		$class = MappingArrays::string($data, 'class');
		if (!class_exists($class)) {
			throw new \UnexpectedValueException('Unknown mapped class '.$class);
		}
		$columns = [];
		foreach (MappingArrays::list($data, 'columns') as $columnData) {
			if (!is_array($columnData)) {
				throw new \UnexpectedValueException('Mapping array: invalid column');
			}
			$column = ColumnMapping::fromArray($columnData);
			$columns[$column->property] = $column;
		}
		$relations = [];
		foreach (MappingArrays::list($data, 'relations') as $relationData) {
			if (!is_array($relationData)) {
				throw new \UnexpectedValueException('Mapping array: invalid relation');
			}
			$relation = RelationMapping::fromArray($relationData);
			$relations[$relation->property] = $relation;
		}
		try {
			return new self(
				$class,
				MappingArrays::string($data, 'table'),
				MappingArrays::string($data, 'element'),
				MappingArrays::string($data, 'idProperty'),
				$columns,
				$relations,
				MappingArrays::nullableString($data, 'extraFieldsProperty')
			);
		} catch (\LogicException $e) {
			throw new \UnexpectedValueException($e->getMessage(), 0, $e);
		}
	}
}
