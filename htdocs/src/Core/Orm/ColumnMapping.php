<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * One mapped property and its column.
 *
 * @phpstan-type ColumnArray array{property: string, name: string, cast: string, nullable: bool, enumClass: ?string}
 */
final class ColumnMapping
{
	/** Casts a #[Column] may name; 'enum' is deduced from a backed enum type, never written. */
	public const CASTS = ['int', 'float', 'bool', 'string', 'json', 'date', 'datetime'];

	/**
	 * @param string                       $property  Property (and constructor parameter) name
	 * @param string                       $name      Column name
	 * @param string                       $cast      One of CASTS, or 'enum'
	 * @param bool                         $nullable  The property accepts null
	 * @param class-string<\BackedEnum>|null $enumClass Enum of an 'enum' cast
	 */
	public function __construct(
		public readonly string $property,
		public readonly string $name,
		public readonly string $cast,
		public readonly bool $nullable,
		public readonly ?string $enumClass = null
	) {
	}

	/**
	 * @return ColumnArray
	 */
	public function toArray(): array
	{
		return [
			'property' => $this->property,
			'name' => $this->name,
			'cast' => $this->cast,
			'nullable' => $this->nullable,
			'enumClass' => $this->enumClass,
		];
	}

	/**
	 * @param array<mixed> $data
	 * @throws \UnexpectedValueException When the array is not a ColumnArray
	 */
	public static function fromArray(array $data): self
	{
		$enumClass = $data['enumClass'] ?? null;
		if ($enumClass !== null && (!is_string($enumClass) || !is_subclass_of($enumClass, \BackedEnum::class))) {
			throw new \UnexpectedValueException('Invalid enum class in column mapping');
		}
		$cast = MappingArrays::string($data, 'cast');
		if ($cast !== 'enum' && !in_array($cast, self::CASTS, true)) {
			throw new \UnexpectedValueException('Invalid cast in column mapping');
		}

		return new self(
			MappingArrays::string($data, 'property'),
			MappingArrays::string($data, 'name'),
			$cast,
			MappingArrays::bool($data, 'nullable'),
			$enumClass
		);
	}
}
