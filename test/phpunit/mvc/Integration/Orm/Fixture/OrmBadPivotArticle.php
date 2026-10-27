<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm\Fixture;

use Dolibarr\Core\Orm\Attribute\BelongsToMany;
use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;

/**
 * Article whose relation names a pivot table that is not an identifier (must be refused, never executed).
 */
#[Table('mvc_orm_article')]
final class OrmBadPivotArticle
{
	/**
	 * @param list<OrmTag> $tags
	 */
	public function __construct(
		#[Id] public readonly ?int $id,
		#[Column] public readonly string $title,
		#[Column] public readonly int $entity = 1,
		#[BelongsToMany(OrmTag::class, pivotTable: 'mvc_orm_article_tag; DROP TABLE x', localKey: 'fk_article', foreignKey: 'fk_tag')] public readonly array $tags = []
	) {
	}
}
