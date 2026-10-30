<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

use Dolibarr\Core\Orm\Query;
use Dolibarr\Core\Orm\Repository;

/**
 * Access to the file index llx_ecm_files, every lookup with an explicit entity (see EcmFile). The index is read and
 * written for the entity of the object, not the current one (ruling R2): obtain this repository without the entity
 * scope, as CoreServices registers it (withoutEntityScope()).
 *
 * @extends Repository<EcmFile>
 */
final class EcmFileRepository extends Repository
{
	protected string $entityClass = EcmFile::class;

	/**
	 * @throws \InvalidArgumentException
	 */
	public function findByPath(int $entity, string $filepath, string $filename): ?EcmFile
	{
		return $this->query()
			->where('entity', $entity)
			->where('filepath', $filepath)
			->where('filename', $filename)
			->orderBy('id')
			->first();
	}

	/**
	 * @return list<EcmFile>
	 * @throws \InvalidArgumentException
	 */
	public function inDirectory(int $entity, string $filepath): array
	{
		return $this->query()
			->where('entity', $entity)
			->where('filepath', $filepath)
			->orderBy('position')
			->orderBy('filename')
			->get();
	}

	/**
	 * The position of a new file of the directory: one after the highest one, whatever the entity (EcmFiles::create),
	 * 1 when the directory has no row.
	 *
	 * @throws \Dolibarr\Core\Database\DatabaseException
	 */
	public function nextPosition(string $filepath): int
	{
		$maxPosition = $this->orm->connection()->fetchValue(
			'SELECT MAX(position) FROM @ecm_files WHERE filepath = :filepath',
			['filepath' => $filepath]
		);

		return (int) $maxPosition + 1;
	}
}
