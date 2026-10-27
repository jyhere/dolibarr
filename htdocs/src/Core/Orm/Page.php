<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * One page of entities (Query::paginate()), and the total number of rows the query matches.
 *
 * @template-covariant T of object
 */
final class Page
{
	/**
	 * @param list<T> $items
	 * @param int     $page    1 for the first page
	 */
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly int $page,
		public readonly int $perPage
	) {
	}

	public function hasNext(): bool
	{
		return $this->page * $this->perPage < $this->total;
	}
}
