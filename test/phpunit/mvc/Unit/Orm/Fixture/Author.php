<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm\Fixture;

use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\HasMany;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;

#[Table('author', entityScoped: false)]
final class Author
{
	/**
	 * @param list<Article> $articles
	 */
	public function __construct(
		#[Id, Column('rowid')] public ?int $id,
		#[Column] public string $name,
		#[HasMany(Article::class, foreignKey: 'fk_author')] public array $articles = []
	) {
	}
}
