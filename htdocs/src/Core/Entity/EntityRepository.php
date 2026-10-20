<?php

declare(strict_types=1);

namespace Dolibarr\Core\Entity;

/**
 * The repository of an entity type, as the generic components of the core read it.
 * Implemented by the service named by EntityDefinition::$repositoryId.
 */
interface EntityRepository
{
	/**
	 * The entity of id $id, null when it does not exist (in the current entity).
	 */
	public function find(int $id): ?object;
}
