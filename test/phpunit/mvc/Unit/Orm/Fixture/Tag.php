<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm\Fixture;

use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;

#[Table('tag', entityScoped: false)]
final class Tag
{
	public function __construct(
		#[Id, Column('rowid')] public ?int $id,
		#[Column('label')] public string $name
	) {
	}
}
