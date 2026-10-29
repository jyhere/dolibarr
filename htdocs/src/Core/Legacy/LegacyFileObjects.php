<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

/**
 * The legacy objects the triggers of the attached files receive: \EcmFiles for ECMFILES_*, \Link for LINK_*.
 * An object is read from its row each time, never kept: it must show the row as it is when the trigger runs.
 */
final class LegacyFileObjects
{
	public function __construct(private readonly \DoliDB $database)
	{
	}

	public function ecmFile(int $id): ?\EcmFiles
	{
		require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
		$ecmFile = new \EcmFiles($this->database);

		return $ecmFile->fetch($id) > 0 ? $ecmFile : null;
	}

	public function link(int $id): ?\Link
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';
		$link = new \Link($this->database);

		return $link->fetch($id) > 0 ? $link : null;
	}
}
