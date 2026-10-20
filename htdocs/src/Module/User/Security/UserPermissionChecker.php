<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Security;

use Dolibarr\Core\CurrentUser;
use Dolibarr\Module\User\Entity\User;

/**
 * What the current user may do on a user card. Mirrors htdocs/user/card.php
 * (line numbers of the mirrored rules are given on each method).
 */
final class UserPermissionChecker
{
	/**
	 * @param bool      $advancedPerms   MAIN_USE_ADVANCED_PERMS
	 * @param bool      $salariesEnabled Module salaries enabled
	 * @param bool      $hrmEnabled      Module hrm enabled
	 * @param list<int> $subordinateIds  Ids of the users under the current one in the hierarchy, himself included (\User::getAllChildIds(1))
	 */
	public function __construct(
		private readonly CurrentUser $me,
		private readonly bool $advancedPerms,
		private readonly bool $salariesEnabled,
		private readonly bool $hrmEnabled = false,
		private readonly array $subordinateIds = []
	) {
	}

	/**
	 * card.php l.133 ($permissiontoread) and l.147 (a user always reads his own card).
	 */
	public function canRead(User $target): bool
	{
		if ($this->isSelf($target)) {
			return true;
		}

		return ($this->me->isAdmin() || $this->me->hasRight('user', 'user', 'read')) && $this->sameCompany($target);
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
	 * card.php l.136-141 ($permissiontoreadgroup).
	 */
	public function canReadGroups(User $target): bool
	{
		if (!$this->advancedPerms) {
			return ($this->me->isAdmin() || $this->me->hasRight('user', 'user', 'read')) && $this->sameCompany($target);
		}

		return ($this->me->isAdmin() || $this->me->hasRight('user', 'group_advance', 'read')) && $this->sameCompany($target);
	}

	public function isSelf(User $target): bool
	{
		return $this->me->id() === $target->id;
	}

	/**
	 * empty($user->socid) || $user->socid == $object->socid
	 */
	private function sameCompany(User $target): bool
	{
		return $this->me->companyId() <= 0 || $this->me->companyId() === ($target->companyId ?? 0);
	}
}
