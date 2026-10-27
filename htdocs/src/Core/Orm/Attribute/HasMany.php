<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Attribute;

/**
 * The targets hold the key of this entity: $foreignKey is a column of the target table.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class HasMany
{
	/**
	 * @param class-string $target
	 */
	public function __construct(
		public readonly string $target,
		public readonly string $foreignKey
	) {
	}
}
