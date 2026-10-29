<?php

declare(strict_types=1);

namespace Dolibarr\Module\Documents\Service;

use Dolibarr\Core\Entity\EntityDefinition;

/**
 * The object whose attached files and links an action works on: its entity type, the legacy object loaded for the
 * page (hooks, triggers, thumbnails, the object of the links and of the index rows), its documents directory and
 * the modulepart of the download links.
 */
final class DocumentsTarget
{
	/**
	 * @param string $directory  Absolute documents directory of the object, under DOL_DATA_ROOT, no ending slash
	 * @param string $modulePart The modulepart of document.php and viewimage.php (e.g. 'user')
	 * @param bool   $canWrite   Whether the current user may change the documents (checked by the caller)
	 */
	public function __construct(
		public readonly EntityDefinition $definition,
		public readonly \CommonObject $legacyObject,
		public readonly string $directory,
		public readonly string $modulePart,
		public readonly bool $canWrite
	) {
	}

	/**
	 * The entity of the index rows of the object (ruling R2): the entity of the object when it has one, the current
	 * entity otherwise (dol_dir_list_in_database()).
	 */
	public function indexEntity(): int
	{
		global $conf;

		$objectEntity = (int) ($this->legacyObject->entity ?? 0);

		return $objectEntity !== 0 ? $objectEntity : (int) $conf->entity;
	}
}
