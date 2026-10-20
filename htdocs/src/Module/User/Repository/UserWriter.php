<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Repository;

use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Service\ValidationException;

/**
 * Writes on users. Every method runs the legacy triggers of the matching legacy method
 * and throws ValidationException (translated messages) when the write is refused.
 */
interface UserWriter
{
	/**
	 * @return int Id of the new user
	 * @throws ValidationException
	 */
	public function insert(User $u, int $creatorId): int;

	/**
	 * The admin flag and the login are copied as given: callers must apply
	 * UserPermissionChecker::canEditAdminFlag() and canEditLogin() when building $u.
	 * (The legacy update() ignores admin for a non-admin actor but writes login unconditionally.)
	 *
	 * @throws ValidationException
	 */
	public function update(User $u, int $actorId): void;

	/**
	 * @throws ValidationException
	 */
	public function setStatus(int $id, bool $enabled): void;

	/**
	 * @throws ValidationException
	 */
	public function delete(int $id, int $actorId): void;

	/**
	 * @return string The clear password (generated when $clearPassword is '')
	 * @throws ValidationException
	 */
	public function setPassword(int $id, string $clearPassword, bool $changeLater, int $actorId): string;

	/**
	 * @throws ValidationException
	 */
	public function sendPassword(int $id, string $clearPassword): void;

	/**
	 * @param ?int    $rightId Id of one permission, or null with $module for all the permissions of a module
	 * @param int     $entity  0 for the current entity
	 * @throws ValidationException
	 */
	public function addRights(int $id, ?int $rightId, ?string $module, int $entity): void;

	/**
	 * @param int $entity 0 for the current entity
	 * @throws ValidationException
	 */
	public function delRights(int $id, ?int $rightId, ?string $module, int $entity): void;

	/**
	 * @throws ValidationException
	 */
	public function setInGroup(int $id, int $groupId, int $entity): void;

	/**
	 * @throws ValidationException
	 */
	public function removeFromGroup(int $id, int $groupId, int $entity): void;

	/**
	 * Replaces the tags of the user by these.
	 *
	 * @param list<int> $categoryIds
	 * @throws ValidationException
	 */
	public function setCategories(int $id, array $categoryIds): void;

	/**
	 * Links the user to a contact (and its company), or to a company only ($contactId 0),
	 * or to nothing (both 0): the user becomes external or internal.
	 *
	 * @throws ValidationException
	 */
	public function linkToContact(int $id, int $contactId, int $companyId): void;

	/**
	 * Reloads the permissions (and the menu) of the logged user, after a change on his own rights.
	 */
	public function reloadOwnRights(): void;
}
