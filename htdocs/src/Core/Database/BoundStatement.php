<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * SQL with positional '?' placeholders and the values bound to them, in order.
 */
final class BoundStatement
{
	/**
	 * @param list<int|float|string|null> $values
	 */
	public function __construct(
		public readonly string $sql,
		public readonly array $values,
	) {
	}
}
