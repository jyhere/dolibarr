<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Attribute;

/**
 * Mapped column. The name defaults to the property name in snake_case; the cast ('int', 'float', 'bool',
 * 'string', 'json', 'date', 'datetime') defaults to the one of the PHP type, a backed enum being cast by value.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Column
{
	public function __construct(
		public readonly ?string $name = null,
		public readonly ?string $cast = null
	) {
	}
}
