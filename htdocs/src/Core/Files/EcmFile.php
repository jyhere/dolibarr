<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\ExtraFields;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;

/**
 * A row of the file index llx_ecm_files (\EcmFiles). The index is read and written for the entity of the object
 * the file belongs to, not for the current one: the mapping declares the entity column (the ORM refuses an
 * unscoped mapping that maps it) and EcmFileRepository drops the implicit scope, taking the entity as an explicit
 * criterion.
 *
 * Not mapped: share_pass (secret), note_private, note_public, geolat, geolong, geopoint, georesultcode.
 * The extra fields are those defined for the element type ecm_files.
 */
#[Table('ecm_files', element: 'ecmfiles')]
final class EcmFile
{
	/**
	 * @param array<string, mixed> $extraFields
	 */
	public function __construct(
		#[Id] public readonly ?int $id,
		#[Column] public readonly ?string $ref,
		/** Hash of the content of the file */
		#[Column] public readonly string $label,
		#[Column] public readonly ?string $share,
		#[Column] public readonly int $entity,
		#[Column] public readonly string $filepath,
		#[Column] public readonly string $filename,
		#[Column] public readonly ?string $srcObjectType = null,
		#[Column] public readonly ?int $srcObjectId = null,
		#[Column] public readonly ?string $fullpathOrig = null,
		#[Column] public readonly ?string $description = null,
		#[Column] public readonly ?string $keywords = null,
		#[Column] public readonly ?string $content = null,
		#[Column] public readonly ?string $cover = null,
		#[Column] public readonly ?int $position = null,
		#[Column] public readonly ?string $genOrUploaded = null,
		#[Column] public readonly ?string $extraparams = null,
		#[Column('date_c')] public readonly ?\DateTimeImmutable $createdAt = null,
		#[Column('tms')] public readonly ?\DateTimeImmutable $modifiedAt = null,
		#[Column('fk_user_c')] public readonly ?int $createdBy = null,
		#[Column('fk_user_m')] public readonly ?int $modifiedBy = null,
		#[Column] public readonly ?string $acl = null,
		#[Column] public readonly ?int $agendaId = null,
		#[ExtraFields] public readonly array $extraFields = []
	) {
	}
}
