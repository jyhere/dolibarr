<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Orm\EntityNotFoundException;
use Dolibarr\Core\Orm\Query;
use Dolibarr\Core\Orm\Repository;

/**
 * Access to the file index llx_ecm_files, every lookup with an explicit entity (see EcmFile).
 *
 * @extends Repository<EcmFile>
 */
final class EcmFileRepository extends Repository
{
	protected string $entityClass = EcmFile::class;

	/**
	 * Without the implicit entity scope, which is the current entity: see EcmFile.
	 *
	 * @return Query<EcmFile>
	 * @throws \InvalidArgumentException Invalid alias
	 */
	public function query(string $alias = 't'): Query
	{
		return parent::query($alias)->withoutEntityScope();
	}

	/**
	 * @param EcmFile $entity
	 * @return EcmFile
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 * @throws EntityNotFoundException
	 * @throws DatabaseException
	 */
	public function save(object $entity): object
	{
		return $this->orm->save($entity, false);
	}

	/**
	 * @param EcmFile $entity
	 * @throws \InvalidArgumentException
	 * @throws EntityNotFoundException
	 * @throws DatabaseException
	 */
	public function delete(object $entity): void
	{
		$this->orm->delete($entity, false);
	}

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
