<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * Runs a Query (the Repository that built it): Query only compiles, its execution methods delegate here.
 */
interface QueryExecutor
{
	/**
	 * @return list<object>
	 */
	public function get(Query $query): array;

	public function first(Query $query): ?object;

	public function count(Query $query): int;

	public function exists(Query $query): bool;

	public function paginate(Query $query, int $page, int $perPage): object;

	/**
	 * @return list<array<string, mixed>>
	 */
	public function rows(Query $query): array;
}
