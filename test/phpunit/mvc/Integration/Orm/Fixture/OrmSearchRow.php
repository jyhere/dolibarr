<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm\Fixture;

use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;

/**
 * Row of the natural search equivalence tests (llx_mvc_orm_search): no entity column, no extra fields.
 */
#[Table('mvc_orm_search')]
final class OrmSearchRow
{
	public function __construct(
		#[Id] public readonly ?int $id,
		#[Column] public readonly ?string $label = null,
		#[Column] public readonly ?string $code = null,
		#[Column] public readonly ?int $qty = null,
		#[Column] public readonly ?float $amount = null,
		#[Column] public readonly ?string $phone = null,
		#[Column] public readonly ?string $tags = null
	) {
	}
}
