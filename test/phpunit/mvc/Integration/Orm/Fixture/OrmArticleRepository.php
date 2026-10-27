<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm\Fixture;

use Dolibarr\Core\Orm\Query;
use Dolibarr\Core\Orm\Repository;

/**
 * @extends Repository<OrmArticle>
 */
final class OrmArticleRepository extends Repository
{
	protected string $entityClass = OrmArticle::class;

	/**
	 * @return Query<OrmArticle>
	 */
	public function published(): Query
	{
		return $this->query()->where('status', 1);
	}
}
