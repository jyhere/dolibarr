<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

use Dolibarr\Core\Orm\Attribute\BelongsTo;
use Dolibarr\Core\Orm\Attribute\BelongsToMany;
use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\ExtraFields;
use Dolibarr\Core\Orm\Attribute\HasMany;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;

/**
 * Reads the mapping of an entity class from its attributes. The entity is built by its constructor (Hydrator):
 * every mapped, relation and extra fields property is a promoted constructor parameter, and every other
 * constructor parameter has a default (a computed value such as User::$hasApiKey is simply not mapped).
 */
final class MappingReader
{
	/** Columns never mapped on an entity (spec §5.3): secrets stay out of the read models. */
	public const SECRET_COLUMNS = ['pass', 'pass_crypted', 'pass_temp', 'api_key', 'openid', 'ldap_sid'];

	/**
	 * @param class-string $class
	 * @throws \LogicException When the class is not a valid entity mapping
	 */
	public function read(string $class): EntityMapping
	{
		$reflection = new \ReflectionClass($class);
		$tables = $reflection->getAttributes(Table::class);
		if ($tables === []) {
			throw new \LogicException($class.': missing #[Table] attribute');
		}
		$table = $tables[0]->newInstance();

		$parameters = [];
		foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
			$parameters[$parameter->getName()] = $parameter;
		}

		$columns = [];
		$relations = [];
		$idProperty = null;
		$extraFieldsProperty = null;
		$mappedProperties = [];
		foreach ($reflection->getProperties() as $property) {
			if ($property->isStatic()) {
				continue;
			}
			$name = $property->getName();
			$isId = $property->getAttributes(Id::class) !== [];
			$columnAttributes = $property->getAttributes(Column::class);
			$relation = $this->relation($property);
			$isExtraFields = $property->getAttributes(ExtraFields::class) !== [];
			if (!$isId && $columnAttributes === [] && $relation === null && !$isExtraFields) {
				continue;
			}
			if ((int) ($isId || $columnAttributes !== []) + (int) ($relation !== null) + (int) $isExtraFields > 1) {
				throw new \LogicException($class.'::$'.$name.': a property is a column, a relation or the extra fields, not several');
			}
			$parameter = $parameters[$name] ?? null;
			if ($parameter === null || !$parameter->isPromoted()) {
				throw new \LogicException($class.'::$'.$name.': a mapped property must be a promoted constructor parameter');
			}
			$mappedProperties[$name] = true;

			if ($relation !== null) {
				if (!$parameter->isDefaultValueAvailable()) {
					throw new \LogicException($class.'::$'.$name.': a relation property needs a default value');
				}
				$relations[$name] = $relation;
				continue;
			}
			if ($isExtraFields) {
				if ($extraFieldsProperty !== null) {
					throw new \LogicException($class.': only one #[ExtraFields] property');
				}
				$type = $property->getType();
				if (!$type instanceof \ReflectionNamedType || $type->getName() !== 'array' || !$parameter->isDefaultValueAvailable()) {
					throw new \LogicException($class.'::$'.$name.': #[ExtraFields] needs an array property with a default value');
				}
				$extraFieldsProperty = $name;
				continue;
			}

			$attribute = $columnAttributes !== [] ? $columnAttributes[0]->newInstance() : new Column();
			$columnName = $attribute->name ?? ($isId ? 'rowid' : self::snakeCase($name));
			if (in_array(strtolower($columnName), self::SECRET_COLUMNS, true)) {
				throw new \LogicException($class.'::$'.$name.': the secret column '.$columnName.' cannot be mapped (spec §5.3)');
			}
			foreach ($columns as $existing) {
				if (strcasecmp($existing->name, $columnName) === 0) {
					throw new \LogicException($class.': $'.$existing->property.' and $'.$name.' both map the column '.$columnName);
				}
			}
			if ($isId) {
				if ($idProperty !== null) {
					throw new \LogicException($class.': only one #[Id] property');
				}
				$idProperty = $name;
			}
			$columns[$name] = $this->column($class, $property, $columnName, $attribute->cast);
		}

		if ($idProperty === null) {
			throw new \LogicException($class.': missing #[Id] property');
		}
		foreach ($parameters as $name => $parameter) {
			if (!isset($mappedProperties[$name]) && !$parameter->isDefaultValueAvailable()) {
				throw new \LogicException($class.': the constructor parameter $'.$name.' is not mapped and has no default value');
			}
		}

		return new EntityMapping($class, $table->name, $table->element ?? $table->name, $idProperty, $columns, $relations, $extraFieldsProperty);
	}

	private function relation(\ReflectionProperty $property): ?RelationMapping
	{
		$name = $property->getName();
		foreach ($property->getAttributes(BelongsTo::class) as $attribute) {
			$belongsTo = $attribute->newInstance();

			return new RelationMapping($name, RelationMapping::BELONGS_TO, $belongsTo->target, $belongsTo->foreignKey);
		}
		foreach ($property->getAttributes(HasMany::class) as $attribute) {
			$hasMany = $attribute->newInstance();

			return new RelationMapping($name, RelationMapping::HAS_MANY, $hasMany->target, $hasMany->foreignKey);
		}
		foreach ($property->getAttributes(BelongsToMany::class) as $attribute) {
			$belongsToMany = $attribute->newInstance();

			return new RelationMapping($name, RelationMapping::BELONGS_TO_MANY, $belongsToMany->target, $belongsToMany->foreignKey, $belongsToMany->pivotTable, $belongsToMany->localKey);
		}

		return null;
	}

	/**
	 * @throws \LogicException When the cast cannot be deduced or does not fit the property type
	 */
	private function column(string $class, \ReflectionProperty $property, string $columnName, ?string $explicitCast): ColumnMapping
	{
		$label = $class.'::$'.$property->getName();
		$type = $property->getType();
		if ($type !== null && !$type instanceof \ReflectionNamedType) {
			throw new \LogicException($label.': a mapped property has a single type');
		}
		$nullable = $type === null || $type->allowsNull();
		$typeName = $type?->getName();

		if ($typeName !== null && is_subclass_of($typeName, \BackedEnum::class)) {
			if ($explicitCast !== null) {
				throw new \LogicException($label.': a backed enum property takes no cast');
			}

			return new ColumnMapping($property->getName(), $columnName, 'enum', $nullable, $typeName);
		}

		$deduced = match ($typeName) {
			'int' => 'int',
			'float' => 'float',
			'bool' => 'bool',
			'string' => 'string',
			'array' => 'json',
			\DateTimeImmutable::class, \DateTimeInterface::class => 'datetime',
			default => null,
		};
		if ($explicitCast !== null && !in_array($explicitCast, ColumnMapping::CASTS, true)) {
			throw new \LogicException($label.': unknown cast '.$explicitCast);
		}
		if ($typeName !== null && $deduced === null) {
			throw new \LogicException($label.': no cast for the type '.$typeName);
		}
		$cast = $explicitCast ?? $deduced;
		if ($cast === null) {
			throw new \LogicException($label.': an untyped property needs a cast');
		}
		if ($deduced !== null && $cast !== $deduced && !($deduced === 'datetime' && $cast === 'date')) {
			throw new \LogicException($label.': the cast '.$cast.' does not fit the type '.$typeName);
		}

		return new ColumnMapping($property->getName(), $columnName, $cast, $nullable);
	}

	private static function snakeCase(string $name): string
	{
		return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name));
	}
}
