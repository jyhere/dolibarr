<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * One relation property (#[BelongsTo], #[HasMany], #[BelongsToMany]).
 *
 * @phpstan-type RelationArray array{property: string, kind: string, target: string, foreignKey: string, pivotTable: ?string, localKey: ?string}
 */
final class RelationMapping
{
	public const BELONGS_TO = 'belongsTo';
	public const HAS_MANY = 'hasMany';
	public const BELONGS_TO_MANY = 'belongsToMany';

	/**
	 * @param class-string $target
	 * @param string|null  $pivotTable Pivot table without prefix (BELONGS_TO_MANY only)
	 * @param string|null  $localKey   Pivot column pointing to this entity (BELONGS_TO_MANY only)
	 */
	public function __construct(
		public readonly string $property,
		public readonly string $kind,
		public readonly string $target,
		public readonly string $foreignKey,
		public readonly ?string $pivotTable = null,
		public readonly ?string $localKey = null
	) {
	}

	/**
	 * @return RelationArray
	 */
	public function toArray(): array
	{
		return [
			'property' => $this->property,
			'kind' => $this->kind,
			'target' => $this->target,
			'foreignKey' => $this->foreignKey,
			'pivotTable' => $this->pivotTable,
			'localKey' => $this->localKey,
		];
	}

	/**
	 * @param array<mixed> $data
	 * @throws \UnexpectedValueException When the array is not a RelationArray
	 */
	public static function fromArray(array $data): self
	{
		$kind = MappingArrays::string($data, 'kind');
		if (!in_array($kind, [self::BELONGS_TO, self::HAS_MANY, self::BELONGS_TO_MANY], true)) {
			throw new \UnexpectedValueException('Invalid relation kind in mapping');
		}
		$target = MappingArrays::string($data, 'target');
		if (!class_exists($target)) {
			throw new \UnexpectedValueException('Unknown relation target '.$target);
		}

		return new self(
			MappingArrays::string($data, 'property'),
			$kind,
			$target,
			MappingArrays::string($data, 'foreignKey'),
			MappingArrays::nullableString($data, 'pivotTable'),
			MappingArrays::nullableString($data, 'localKey')
		);
	}
}
