<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * The entities (multicompany) whose rows a query on an element may read: the integers of getEntity($element).
 * The legacy implementation is LegacyEntityScope (Core/Legacy).
 */
interface EntityScope
{
	/**
	 * @return list<int>
	 */
	public function entitiesFor(string $element): array;
}
