<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Repository;

use Dolibarr\Module\User\Entity\RightDefinition;

/**
 * The rights of the rights tab (htdocs/user/perms.php) for one user: by module, in the order of perms.php,
 * and the ids of the rights he has from his groups by entity ($permsgroupbyentity, l.372-406).
 */
final class RightsCatalog
{
	/**
	 * @param array<string, list<RightDefinition>> $byModule
	 * @param array<int, list<int>>                $permsGroupByEntity
	 */
	public function __construct(public readonly array $byModule, public readonly array $permsGroupByEntity)
	{
	}
}
