<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Attribute;

/**
 * Link through a pivot table (without prefix): $localKey points to this entity, $foreignKey to the target.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class BelongsToMany
{
	/**
	 * @param class-string $target
	 */
	public function __construct(
		public readonly string $target,
		public readonly string $pivotTable,
		public readonly string $localKey,
		public readonly string $foreignKey
	) {
	}
}
