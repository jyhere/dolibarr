<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * SQL of a Query for Connection::fetchAll(): named placeholders (:p1, :p2…, :entities) and '@table' names.
 */
final class CompiledQuery
{
	/**
	 * @param array<string, mixed> $params Values by placeholder name, without the colon (a list for IN)
	 */
	public function __construct(
		public readonly string $sql,
		public readonly array $params
	) {
	}
}
