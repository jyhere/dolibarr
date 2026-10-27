<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm\Fixture;

use Dolibarr\Core\Orm\Attribute\BelongsTo;
use Dolibarr\Core\Orm\Attribute\BelongsToMany;
use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\ExtraFields;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;

#[Table('article', element: 'blog_article')]
final class Article
{
	/**
	 * @param array<string, mixed>  $metadata
	 * @param list<Tag>             $tags
	 * @param array<string, mixed>  $extraFields
	 */
	public function __construct(
		#[Id] public readonly ?int $id,
		#[Column] public readonly string $title,
		#[Column('statut')] public readonly ArticleStatus $status,
		#[Column] public readonly ?float $price,
		#[Column] public readonly bool $featured,
		#[Column] public readonly int $viewCount,
		#[Column('fk_author')] public readonly ?int $authorId,
		#[Column(cast: 'date')] public readonly ?\DateTimeImmutable $publishedOn,
		#[Column('datec')] public readonly ?\DateTimeImmutable $createdAt,
		#[Column] public readonly array $metadata,
		#[Column] public readonly int $entity = 1,
		#[Column] public readonly ?string $summary = null,
		#[BelongsTo(Author::class, foreignKey: 'fk_author')] public readonly ?Author $author = null,
		#[BelongsToMany(Tag::class, pivotTable: 'article_tag', localKey: 'fk_article', foreignKey: 'fk_tag')] public readonly array $tags = [],
		#[ExtraFields] public readonly array $extraFields = [],
		public readonly bool $hasSummary = false
	) {
	}
}
