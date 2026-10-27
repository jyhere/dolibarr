<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Attribute;

/**
 * The entity holds the key of the target: $foreignKey is a column of this entity's table.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class BelongsTo
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
