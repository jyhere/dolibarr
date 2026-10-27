<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm\Fixture;

use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;

#[Table('mvc_orm_tag')]
final class OrmTag
{
	public function __construct(
		#[Id] public readonly ?int $id,
		#[Column] public readonly string $label
	) {
	}
}
