<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Security;

use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Entity\EntityPermissions;
use Dolibarr\Module\User\Entity\User;

/**
 * What the current user may do on users. Mirrors htdocs/user/card.php, list.php and perms.php
 * (file and line numbers of the mirrored rules are given on each method).
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
	 * user/document.php l.70-80 ($permissiontoedit, used as $permissiontoadd by actions_linkedfiles): same rule as the notes.
	 */
	public function canEditDocuments(object $target): bool
	{
		return $this->canEditNotes($target);
	}

	/**
	 * card.php l.171-175 ($permissiontoseesalary). As there, the hierarchy-less fallback is not limited to internal users,
	 * and a new user (id 0, the creation form) counts as one under the current user ($id == 0).
	 */
	public function canSeeSalary(User $target): bool
	{
		$subordinate = $target->id === 0 || in_array($target->id, $this->subordinateIds, true);
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
	 * Action confirm_disable: card.php l.135 ($permissiontodisable), l.216 (a user can't disable
	 * himself) and l.218-221 (only an admin disables an admin).
	 */
	public function canDisable(User $target): bool
	{
		return $this->permissionToDisable($target) && !$this->isSelf($target) && ($this->me->isAdmin() || !$target->admin);
	}

	/**
	 * Action confirm_enable: card.php l.135 ($permissiontodisable) and l.231 (not oneself).
	 * The users quota ($dolibarr_main_limit_users, l.235-241) is not a permission and is left to the caller.
	 */
	public function canEnable(User $target): bool
	{
		return $this->permissionToDisable($target) && !$this->isSelf($target);
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
	 * card.php l.136-139 ($permissiontoreadgroup): the groups block of the card.
	 */
	public function canReadGroups(User $target): bool
	{
		$right = $this->advancedPerms ? $this->me->hasRight('user', 'group_advance', 'read') : $this->me->hasRight('user', 'user', 'read');

		return ($this->me->isAdmin() || $right) && $this->sameCompany($target);
	}

	/**
	 * card.php l.3321 ($genallowed of the documents block); the deletion is canWriteUsers() (l.3322).
	 */
	public function canGenerateDocuments(): bool
	{
		return $this->me->hasRight('user', 'user', 'read');
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
	 * Modify button through the password right, when canModify() is false: card.php l.2315, hardened with
	 * canChangeCredentials() (the only thing that form lets change is the password).
	 */
	public function canModifyForPassword(User $target, bool $ldapManaged): bool
	{
		return $this->canSetPassword($target) && !$ldapManaged && $this->inEditableEntity($target) && $this->canChangeCredentials($target);
	}

	/**
	 * ReinitPassword button on an enabled user: card.php l.2344, hardened with canChangeCredentials().
	 */
	public function canReinitPassword(User $target, bool $ldapManaged): bool
	{
		return !$this->isSelf($target) && $this->canSetPassword($target) && $target->login !== '' && !$ldapManaged && $this->inManageableEntity($target)
			&& $this->canChangeCredentials($target);
	}

	/**
	 * SendNewPassword button on an enabled user: card.php l.2353, hardened with canChangeCredentials().
	 */
	public function canSendNewPassword(User $target, bool $ldapManaged): bool
	{
		return !$this->isSelf($target) && $this->canSendPassword($target) && $target->login !== '' && !$ldapManaged && $this->inManageableEntity($target)
			&& $this->canChangeCredentials($target);
	}

	/**
	 * Hardening over card.php (which only protects the admins against a deletion or a disabling): the
	 * password, the login and the email of an admin are only changed by an admin, those of a superadmin
	 * (an admin without entity) only by a superadmin. Oneself always.
	 */
	public function canChangeCredentials(User $target): bool
	{
		if ($this->isSelf($target) || !$target->admin) {
			return true;
		}

		return $this->me->isAdmin() && !($target->entity === 0 && $this->me->entity() !== 0);
	}

	/**
	 * Third party and contact fields of the edit form: card.php l.2557, an admin on another user.
	 */
	public function canLinkCompany(User $target): bool
	{
		return $this->me->isAdmin() && !$this->isSelf($target);
	}

	/**
	 * Reactivate button: card.php l.2365.
	 */
	public function canReactivate(User $target): bool
	{
		return !$this->isSelf($target) && $this->permissionToDisable($target) && $target->status === 0 && $this->inManageableEntity($target);
	}

	/**
	 * DisableUser button: card.php l.2371 (oneself gets a disabled button, l.2376).
	 */
	public function canDeactivate(User $target): bool
	{
		return !$this->isSelf($target) && $this->permissionToDisable($target) && $target->status === 1 && $this->inManageableEntity($target);
	}

	/**
	 * Action confirm_delete: card.php l.135 ($permissiontodisable), l.254 (not oneself), l.261-263
	 * (only an admin deletes an admin) and l.264-267 (only a superadmin deletes a superadmin).
	 */
	public function canDelete(User $target): bool
	{
		return $this->permissionToDisable($target) && !$this->isSelf($target)
			&& ($this->me->isAdmin() || !$target->admin)
			&& !($target->admin && $target->entity === 0 && $this->me->entity() !== 0);
	}

	/**
	 * DeleteUser button shown: card.php l.2382 (enabled or not by canDeleteAdminTarget()).
	 */
	public function canSeeDeleteButton(User $target): bool
	{
		return !$this->isSelf($target) && $this->permissionToDisable($target) && $this->inManageableEntity($target);
	}

	/**
	 * DeleteUser button enabled: card.php l.2384 (only an admin deletes an admin).
	 */
	public function canDeleteAdminTarget(User $target): bool
	{
		return $this->me->isAdmin() || !$target->admin;
	}

	/**
	 * Admin flag editable: card.php l.520-524 on update (an admin, not on himself), l.1184 on
	 * creation (admin only) and l.2620-2626 (never on an external user). The "last superadmin"
	 * protection of l.2627-2630 needs user counts and is left to the form. Hardened over card.php: the flag of a
	 * superadmin is only changed by a superadmin (canChangeCredentials()).
	 */
	public function canEditAdminFlag(User $target): bool
	{
		return $this->me->isAdmin() && !$this->isSelf($target) && !$target->isExternal() && $this->canChangeCredentials($target);
	}

	/**
	 * Login editable: card.php l.525 and l.2544 (an admin, on a user not managed by LDAP);
	 * on creation (id 0) the login is always typed unless it comes from LDAP (l.1178).
	 *
	 * @param bool $ldapManaged The target has an ldap_sid (Active Directory)
	 */
	public function canEditLogin(User $target, bool $ldapManaged = false): bool
	{
		if ($ldapManaged) {
			return false;
		}

		return $target->id === 0 || $this->me->isAdmin();
	}

	/**
	 * perms.php l.67, l.72 ($canreaduser) for others; l.84 for oneself: always allowed
	 * unless advanced permissions are on without user->self_advance->readperms (admins excepted).
	 */
	public function canReadRights(User $target): bool
	{
		if ($this->isSelf($target)) {
			return !$this->advancedPerms || $this->me->isAdmin() || $this->me->hasRight('user', 'self_advance', 'readperms');
		}
		$read = $this->me->hasRight('user', 'user', 'read') && (!$this->advancedPerms || $this->me->hasRight('user', 'user_advance', 'readperms'));

		return ($this->me->isAdmin() || $read) && $this->sameCompany($target);
	}

	/**
	 * perms.php l.69 ($caneditperms) and l.73-74 (user->self_advance->writeperms on oneself with advanced permissions).
	 * The sameCompany() condition is an addition over perms.php (stricter, kept deliberately so
	 * that an external user never edits the rights of a user of another company).
	 */
	public function canEditRights(User $target): bool
	{
		$self = $this->advancedPerms && $this->isSelf($target) && $this->me->hasRight('user', 'self_advance', 'writeperms');

		return ($this->me->isAdmin() || $this->me->hasRight('user', 'user', 'write') || $self) && $this->sameCompany($target);
	}

	/**
	 * list.php l.133 ($permissiontoreadhr): HR personal information.
	 */
	public function canReadHr(): bool
	{
		return $this->me->hasRight('hrm', 'read_personal_information', 'read') || $this->me->hasRight('hrm', 'write_personal_information', 'write');
	}

	/**
	 * card.php l.2214: the API key line is shown to the user himself or to an admin.
	 */
	public function canReadApiKey(User $target): bool
	{
		return $this->isSelf($target) || $this->me->isAdmin();
	}

	/**
	 * list.php l.227 ($permissiontoadd, forced false in multicompany transverse mode for a user
	 * of an entity) which gates the mass actions of l.655-666.
	 */
	public function canUseMassActions(): bool
	{
		if ($this->multicompany && $this->transverseMode && $this->me->entity() !== 0) {
			return false;
		}

		return $this->me->isAdmin() || $this->me->hasRight('user', 'user', 'write');
	}

	/**
	 * \User::getAllChildIds(1) of the current user (list.php l.251): the users under him, himself included.
	 *
	 * @return list<int>
	 */
	public function subordinateIds(): array
	{
		return $this->subordinateIds;
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
	 * card.php l.135 ($permissiontodisable).
	 */
	private function permissionToDisable(User $target): bool
	{
		return ($this->me->isAdmin() || $this->me->hasRight('user', 'user', 'delete')) && $this->sameCompany($target);
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
