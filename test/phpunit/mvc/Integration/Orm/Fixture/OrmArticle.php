<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm\Fixture;

use Dolibarr\Core\Orm\Attribute\BelongsTo;
use Dolibarr\Core\Orm\Attribute\BelongsToMany;
use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\ExtraFields;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;

/**
 * Readonly entity of the repository tests (llx_mvc_orm_article, with extra fields).
 */
#[Table('mvc_orm_article')]
final class OrmArticle
{
	/**
	 * @param list<OrmTag>          $tags
	 * @param array<string, mixed>  $extraFields
	 */
	public function __construct(
		#[Id] public readonly ?int $id,
		#[Column] public readonly string $title,
		#[Column] public readonly int $status = 0,
		#[Column] public readonly ?float $price = null,
		#[Column(cast: 'date')] public readonly ?\DateTimeImmutable $publishedOn = null,
		#[Column('datec')] public readonly ?\DateTimeImmutable $createdAt = null,
		#[Column('fk_author')] public readonly ?int $authorId = null,
		#[Column] public readonly int $entity = 1,
		#[BelongsTo(OrmAuthor::class, foreignKey: 'fk_author')] public readonly ?OrmAuthor $author = null,
		#[BelongsToMany(OrmTag::class, pivotTable: 'mvc_orm_article_tag', localKey: 'fk_article', foreignKey: 'fk_tag')] public readonly array $tags = [],
		#[ExtraFields] public readonly array $extraFields = []
	) {
	}
}
