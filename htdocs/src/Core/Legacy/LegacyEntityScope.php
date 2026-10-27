<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\Orm\EntityScope;

/**
 * EntityScope of the legacy multicompany: the integers of getEntity($element) (shared entities, the 0 entity of
 * the elements getEntity() adds it to, the hookGetEntity hooks).
 */
final class LegacyEntityScope implements EntityScope
{
	public function entitiesFor(string $element): array
	{
		$entities = [];
		foreach (explode(',', (string) getEntity($element)) as $entity) {
			$entity = trim($entity);
			if (preg_match('/^-?\d+$/D', $entity) === 1) {
				$entities[] = (int) $entity;
			}
		}

		return array_values(array_unique($entities));
	}
}
