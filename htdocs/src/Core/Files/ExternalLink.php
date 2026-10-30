<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\ExtraFields;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;

/**
 * A link attached to an object (llx_links, \Link). Like EcmFile, its repository drops the implicit entity scope
 * and filters the entity explicitly. share_pass (secret) is not mapped.
 */
#[Table('links', element: 'link')]
final class ExternalLink
{
	/**
	 * @param array<string, mixed> $extraFields
	 */
	public function __construct(
		#[Id] public readonly ?int $id,
		#[Column] public readonly int $entity,
		#[Column('datea')] public readonly \DateTimeImmutable $linkedAt,
		#[Column] public readonly string $url,
		#[Column] public readonly string $label,
		#[Column('objecttype')] public readonly string $objectType,
		#[Column('objectid')] public readonly int $objectId,
		#[Column] public readonly ?string $share = null,
		#[ExtraFields] public readonly array $extraFields = []
	) {
	}
}
