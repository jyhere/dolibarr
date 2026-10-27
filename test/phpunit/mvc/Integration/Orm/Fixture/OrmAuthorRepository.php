<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm\Fixture;

use Dolibarr\Core\Orm\Repository;

/**
 * @extends Repository<OrmAuthor>
 */
final class OrmAuthorRepository extends Repository
{
	protected string $entityClass = OrmAuthor::class;
}
