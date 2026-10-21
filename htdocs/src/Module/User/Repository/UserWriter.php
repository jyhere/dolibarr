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
	public function insert(User $user, int $creatorId): int;

	/**
	 * The admin flag and the login are copied as given: callers must apply
	 * UserPermissionChecker::canEditAdminFlag() and canEditLogin() when building $user.
	 * (The legacy update() ignores admin for a non-admin actor but writes login unconditionally.)
	 * The company and the contact of $user are not written: linkToContact() does it.
	 *
	 * @throws ValidationException
	 */
	public function update(User $user, int $actorId): void;

	/**
	 * Whether $clearPassword is the stored password of the user, the test \User::update() makes before
	 * calling setPassword() (user.class.php l.2474-2476). The stored password never leaves the writer.
	 *
	 * @throws ValidationException When the user does not exist
	 */
	public function isCurrentPassword(int $id, string $clearPassword): bool;

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
	 * @return bool The contact has no third party while $companyId was given: the company is cleared
	 *              (card.php l.665-668, WarningUserDifferentContactSocid)
	 * @throws ValidationException
	 */
	public function linkToContact(int $id, int $contactId, int $companyId): bool;

	/**
	 * Reloads the permissions (and the menu) of the logged user, after a change on his own rights.
	 */
	public function reloadOwnRights(): void;

	/**
	 * Saves the columns the current user chose for a list (actions_changeselectedfields.inc.php): the personal
	 * constant MAIN_SELECTEDFIELDS_<varpage>, '' to go back to the defaults.
	 *
	 * @throws ValidationException
	 */
	public function saveListFields(string $varpage, string $fields): void;
}
