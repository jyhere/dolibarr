<?php

declare(strict_types=1);

namespace Dolibarr\Core\Entity;

/**
 * What the current user may do on an entity, whatever its type.
 */
interface EntityPermissions
{
	public function canRead(object $entity): bool;

	public function canEditNotes(object $entity): bool;
}
