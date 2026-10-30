<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

use Dolibarr\Core\Orm\Query;
use Dolibarr\Core\Orm\Repository;

/**
 * Access to the links of objects (llx_links), every lookup with an explicit entity (see ExternalLink). The entity is
 * a criterion of the lookups, not the implicit scope (ruling R2): obtain this repository without the entity scope,
 * as CoreServices registers it (withoutEntityScope()).
 *
 * @extends Repository<ExternalLink>
 */
final class ExternalLinkRepository extends Repository
{
	protected string $entityClass = ExternalLink::class;

	/**
	 * The links of an object, oldest first. The entity filter is the one of \Link::fetchAll(): the current entity,
	 * none when it is 0.
	 *
	 * @return list<ExternalLink>
	 * @throws \InvalidArgumentException
	 */
	public function forObject(string $objectType, int $objectId, int $currentEntity): array
	{
		return $this->ofObject($objectType, $objectId, $currentEntity)
			->orderBy('linkedAt')
			->orderBy('id')
			->get();
	}

	/**
	 * The link $id when it belongs to this object, null otherwise (a link id of another object is refused).
	 *
	 * @throws \InvalidArgumentException
	 */
	public function findForObject(int $id, string $objectType, int $objectId, int $currentEntity): ?ExternalLink
	{
		return $this->ofObject($objectType, $objectId, $currentEntity)->where('id', $id)->first();
	}

	/**
	 * @return Query<ExternalLink>
	 * @throws \InvalidArgumentException
	 */
	private function ofObject(string $objectType, int $objectId, int $currentEntity): Query
	{
		$query = $this->query()->where('objectType', $objectType)->where('objectId', $objectId);
		if ($currentEntity !== 0) {
			$query->where('entity', $currentEntity);
		}

		return $query;
	}
}
