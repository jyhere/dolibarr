<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * Runs a Query (Orm, for the Repository that built it): Query only compiles, its execution methods delegate here.
 */
interface QueryExecutor
{
	/**
	 * @template T of object
	 * @param Query<T> $query
	 * @return list<T>
	 */
	public function get(Query $query): array;

	/**
	 * @template T of object
	 * @param Query<T> $query
	 * @return T|null
	 */
	public function first(Query $query): ?object;

	/**
	 * @param Query<object> $query
	 */
	public function count(Query $query): int;

	/**
	 * @param Query<object> $query
	 */
	public function exists(Query $query): bool;

	/**
	 * @template T of object
	 * @param Query<T> $query
	 * @return Page<T>
	 * @throws \InvalidArgumentException When $page or $perPage is below 1
	 */
	public function paginate(Query $query, int $page, int $perPage): Page;

	/**
	 * @param Query<object> $query
	 * @return list<array<string, mixed>>
	 */
	public function rows(Query $query): array;
}
