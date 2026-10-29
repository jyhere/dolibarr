<?php

declare(strict_types=1);

namespace Dolibarr\Core\Entity;

/**
 * What the generic attached files tab needs to know of an entity type (its legacy document page: e.g. htdocs/user/document.php).
 * Implemented by the service named by EntityDefinition::$documentsId.
 */
interface EntityDocuments
{
	/**
	 * The modulepart of the entity's files (e.g. 'user').
	 */
	public function modulePart(): string;

	/**
	 * The upload directory of the object ($upload_dir of the legacy page), without trailing slash.
	 */
	public function directory(\CommonObject $legacyObject): string;

	/**
	 * Whether the current user may add, rename, share and delete the files of $entity ($permissiontoadd of the legacy page).
	 */
	public function canWriteDocuments(object $entity): bool;

	/**
	 * The page title ($title of the legacy page).
	 */
	public function pageTitle(\CommonObject $legacyObject): string;

	/**
	 * The saving mask of the uploaded file names ($savingdocmask of document_actions_post_upload.tpl.php): '' for none.
	 */
	public function savingDocMask(\CommonObject $legacyObject): string;
}
