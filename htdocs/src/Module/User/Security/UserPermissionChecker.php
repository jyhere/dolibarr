<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Security;

use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Entity\EntityPermissions;
use Dolibarr\Module\User\Entity\User;

/**
 * What the current user may do on a user card. Mirrors htdocs/user/card.php
 * (line numbers of the mirrored rules are given on each method).
 */
final class UserPermissionChecker implements EntityPermissions
{
	/**
	 * @param bool      $advancedPerms   MAIN_USE_ADVANCED_PERMS
	 * @param bool      $salariesEnabled Module salaries enabled
	 * @param bool      $hrmEnabled      Module hrm enabled
	 * @param list<int> $subordinateIds  Ids of the users under the current one in the hierarchy, himself included (\User::getAllChildIds(1))
	 * @param bool      $multicompany    Module multicompany enabled
	 * @param bool      $transverseMode  MULTICOMPANY_TRANSVERSE_MODE
	 * @param int       $currentEntity   $conf->entity
	 */
	public function __construct(
		private readonly CurrentUser $me,
		private readonly bool $advancedPerms,
		private readonly bool $salariesEnabled,
		private readonly bool $hrmEnabled = false,
		private readonly array $subordinateIds = [],
		private readonly bool $multicompany = false,
		private readonly bool $transverseMode = false,
		private readonly int $currentEntity = 1
	) {
	}

	/**
	 * card.php l.133 ($permissiontoread) and l.147 (a user always reads his own card).
	 */
	public function canRead(object $target): bool
	{
		$target = self::asUser($target);
		if ($this->isSelf($target)) {
			return true;
		}

		return ($this->me->isAdmin() || $this->me->hasRight('user', 'user', 'read')) && $this->sameCompany($target);
	}

	/**
	 * user/note.php l.63-67: $permissiontoeditnote is user->self->write on one's own card, else user->user->write.
	 */
	public function canEditNotes(object $target): bool
	{
		$target = self::asUser($target);

		return $this->isSelf($target) ? $this->me->hasRight('user', 'self', 'write') : $this->me->hasRight('user', 'user', 'write');
	}

	/**
	 * card.php l.171-175 ($permissiontoseesalary). As there, the hierarchy-less fallback is not limited to internal users.
	 */
	public function canSeeSalary(User $target): bool
	{
		$subordinate = in_array($target->id, $this->subordinateIds, true);
		$byRight = $this->me->companyId() <= 0 && (
			($this->salariesEnabled && $this->me->hasRight('salaries', 'read') && $subordinate)
			|| ($this->salariesEnabled && $this->me->hasRight('salaries', 'readall'))
			|| ($this->hrmEnabled && $this->me->hasRight('hrm', 'employee', 'read'))
		);

		return $byRight || (!$this->salariesEnabled && !$this->hrmEnabled && ($this->me->isAdmin() || $subordinate));
	}

	/**
	 * card.php l.155 ($permissiontoedit for an existing user).
	 */
	public function canEdit(User $target): bool
	{
		$right = $this->isSelf($target) ? $this->me->hasRight('user', 'self', 'write') : $this->me->hasRight('user', 'user', 'write');

		return $right && $this->sameCompany($target);
	}

	/**
	 * card.php l.156 ($permissiontoeditpasswordandsee).
	 */
	public function canSetPassword(User $target): bool
	{
		$right = $this->isSelf($target)
			? $this->me->hasRight('user', 'self', 'password')
			: $this->me->hasRight('user', 'user', 'password') && $this->me->isAdmin();

		return $right && $this->sameCompany($target);
	}

	/**
	 * card.php l.157 ($permissiontoeditpasswordandsend).
	 */
	public function canSendPassword(User $target): bool
	{
		$right = $this->isSelf($target) ? $this->me->hasRight('user', 'self', 'password') : $this->me->hasRight('user', 'user', 'password');

		return $right && $this->sameCompany($target);
	}

	/**
	 * card.php l.135 ($permissiontodisable, also used for delete).
	 */
	public function canDisable(User $target): bool
	{
		return ($this->me->isAdmin() || $this->me->hasRight('user', 'user', 'delete')) && $this->sameCompany($target);
	}

	/**
	 * card.php l.137-141 ($permissiontoeditgroup, from the l.134 $permissiontoedit, before its l.155 override).
	 */
	public function canEditGroups(User $target): bool
	{
		$right = $this->advancedPerms ? $this->me->hasRight('user', 'group_advance', 'write') : $this->me->hasRight('user', 'user', 'write');

		return ($this->me->isAdmin() || $right) && $this->sameCompany($target);
	}

	/**
	 * card.php l.132 ($permissiontoadd) for a new user (no company).
	 */
	public function canCreate(): bool
	{
		return ($this->me->isAdmin() || $this->me->hasRight('user', 'user', 'write')) && $this->me->companyId() <= 0;
	}

	/**
	 * card.php l.1690 and l.1750: back-to-list link and banner navigation.
	 */
	public function canList(): bool
	{
		return $this->me->hasRight('user', 'user', 'read') || $this->me->isAdmin();
	}

	/**
	 * card.php l.2294 (empty($user->socid)): internal users only, e.g. the SendMail button.
	 */
	public function isInternal(): bool
	{
		return $this->me->companyId() <= 0;
	}

	/**
	 * Clone button shown: card.php l.132, l.143-153 and l.2330.
	 */
	public function canClone(User $target): bool
	{
		if ($this->transverseMode && $this->currentEntity > 1) {
			return false;
		}
		$add = ($this->me->isAdmin() || $this->me->hasRight('user', 'user', 'write')) && $this->sameCompany($target);

		return ($target->entity === 0 && $add && $this->me->entity() === 0)
			|| ($target->admin && $target->entity !== 0 && $add && $this->me->isAdmin())
			|| ($add && !$target->admin && $target->entity !== 0);
	}

	/**
	 * Clone button enabled: card.php l.2337.
	 */
	public function canWriteUsers(): bool
	{
		return $this->me->hasRight('user', 'user', 'write');
	}

	/**
	 * Modify button through the edit right: card.php l.2307.
	 */
	public function canModify(User $target): bool
	{
		return $this->canEdit($target) && $this->inEditableEntity($target);
	}

	/**
	 * Modify button through the password right, when canModify() is false: card.php l.2315.
	 */
	public function canModifyForPassword(User $target, bool $ldapManaged): bool
	{
		return $this->canSetPassword($target) && !$ldapManaged && $this->inEditableEntity($target);
	}

	/**
	 * ReinitPassword button on an enabled user: card.php l.2344.
	 */
	public function canReinitPassword(User $target, bool $ldapManaged): bool
	{
		return !$this->isSelf($target) && $this->canSetPassword($target) && $target->login !== '' && !$ldapManaged && $this->inManageableEntity($target);
	}

	/**
	 * SendNewPassword button on an enabled user: card.php l.2353.
	 */
	public function canSendNewPassword(User $target, bool $ldapManaged): bool
	{
		return !$this->isSelf($target) && $this->canSendPassword($target) && $target->login !== '' && !$ldapManaged && $this->inManageableEntity($target);
	}

	/**
	 * Reactivate button: card.php l.2365.
	 */
	public function canReactivate(User $target): bool
	{
		return !$this->isSelf($target) && $this->canDisable($target) && $target->status === 0 && $this->inManageableEntity($target);
	}

	/**
	 * DisableUser button: card.php l.2371 (oneself gets a disabled button, l.2376).
	 */
	public function canDeactivate(User $target): bool
	{
		return !$this->isSelf($target) && $this->canDisable($target) && $target->status === 1 && $this->inManageableEntity($target);
	}

	/**
	 * DeleteUser button shown: card.php l.2382.
	 */
	public function canDelete(User $target): bool
	{
		return !$this->isSelf($target) && $this->canDisable($target) && $this->inManageableEntity($target);
	}

	/**
	 * DeleteUser button enabled: card.php l.2384 (only an admin deletes an admin).
	 */
	public function canDeleteAdminTarget(User $target): bool
	{
		return $this->me->isAdmin() || !$target->admin;
	}

	public function isSelf(User $target): bool
	{
		return $this->me->id() === $target->id;
	}

	/**
	 * @throws \InvalidArgumentException When $entity is not a user
	 */
	private static function asUser(object $entity): User
	{
		if (!$entity instanceof User) {
			throw new \InvalidArgumentException('UserPermissionChecker only handles '.User::class.', got '.$entity::class);
		}

		return $entity;
	}

	/**
	 * Entity condition of the Modify button, card.php l.2307.
	 */
	private function inEditableEntity(User $target): bool
	{
		return !$this->multicompany || $this->me->entity() === 0 || $target->entity === $this->currentEntity || ($this->transverseMode && $target->entity === 1);
	}

	/**
	 * Entity condition of the password, enable, disable and delete buttons, card.php l.2345.
	 */
	private function inManageableEntity(User $target): bool
	{
		return (!$this->multicompany && $target->entity === $this->me->entity()) || $this->me->entity() === 0 || $target->entity === $this->currentEntity || ($this->transverseMode && $target->entity === 1);
	}

	/**
	 * empty($user->socid) || $user->socid == $object->socid
	 */
	private function sameCompany(User $target): bool
	{
		return $this->me->companyId() <= 0 || $this->me->companyId() === ($target->companyId ?? 0);
	}
}
