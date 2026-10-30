<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Documents;

use Dolibarr\Core\Entity\EntityDocuments;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Module\User\Security\UserPermissionChecker;

/**
 * The attached files of a user, as htdocs/user/document.php declares them.
 */
final class UserDocuments implements EntityDocuments
{
	public function __construct(
		private readonly LegacyContext $ctx,
		private readonly UserPermissionChecker $permissions
	) {
	}

	public function modulePart(): string
	{
		return 'user';
	}

	/**
	 * document.php l.118-123: for users the directory is always the one of the current entity.
	 */
	public function directory(\CommonObject $legacyObject): string
	{
		return $this->ctx->conf->user->dir_output.'/'.$legacyObject->id;
	}

	/**
	 * @throws \InvalidArgumentException When $entity is not a user
	 */
	public function canWriteDocuments(object $entity): bool
	{
		return $this->permissions->canEditDocuments($entity);
	}

	/**
	 * document.php l.153-154.
	 */
	public function pageTitle(\CommonObject $legacyObject): string
	{
		$lastname = (string) ($legacyObject->lastname ?? '');
		$firstname = (string) ($legacyObject->firstname ?? '');

		return ($firstname !== '' ? $lastname.', '.$firstname : $lastname).' - '.$this->ctx->langs->trans('Documents');
	}

	/**
	 * The user module is not in the list of document_actions_post_upload.tpl.php (l.126-149): no mask.
	 */
	public function savingDocMask(\CommonObject $legacyObject): string
	{
		return '';
	}
}
