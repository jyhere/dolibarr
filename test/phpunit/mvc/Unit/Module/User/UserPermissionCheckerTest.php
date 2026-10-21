<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

final class UserPermissionCheckerTest extends TestCase
{
	public function testOwnerCanReadSelfWithoutRight(): void
	{
		self::assertTrue($this->checker(me: 5)->canRead($this->user(id: 5)));
	}

	public function testNeedsUserReadRightForOthers(): void
	{
		self::assertFalse($this->checker(me: 5)->canRead($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.lire'])->canRead($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, admin: true)->canRead($this->user(id: 6)));
	}

	public function testExternalUserOnlySeesOwnCompany(): void
	{
		$checker = $this->checker(me: 5, rights: ['user.user.lire'], companyId: 10);
		self::assertFalse($checker->canRead($this->user(id: 6, companyId: 11)));
		self::assertFalse($checker->canRead($this->user(id: 6)));
		self::assertTrue($checker->canRead($this->user(id: 6, companyId: 10)));
	}

	public function testCanEditNotesSelfVsOther(): void
	{
		self::assertTrue($this->checker(me: 5, rights: ['user.self.creer'])->canEditNotes($this->user(id: 5)));
		self::assertFalse($this->checker(me: 5, rights: ['user.self.creer'])->canEditNotes($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.creer'])->canEditNotes($this->user(id: 6)));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.creer'])->canEditNotes($this->user(id: 5)));
	}

	public function testRejectsForeignEntities(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->checker(me: 5)->canRead(new \stdClass());
	}

	public function testSalaryHiddenWithoutRight(): void
	{
		$target = $this->user(id: 6);
		// Module salaries enabled: the right is needed, being admin is not enough (card.php l.171-175).
		self::assertFalse($this->checker(me: 5, salaries: true)->canSeeSalary($target));
		self::assertFalse($this->checker(me: 5, admin: true, salaries: true)->canSeeSalary($target));
		self::assertTrue($this->checker(me: 5, rights: ['salaries.readall'], salaries: true)->canSeeSalary($target));
		// salaries->read only for the users under me in the hierarchy.
		self::assertFalse($this->checker(me: 5, rights: ['salaries.read'], salaries: true)->canSeeSalary($target));
		self::assertTrue($this->checker(me: 5, rights: ['salaries.read'], salaries: true, subordinates: [5, 6])->canSeeSalary($target));
		// Right without the module: no salary.
		self::assertFalse($this->checker(me: 5, rights: ['salaries.readall'], hrm: true)->canSeeSalary($target));
		self::assertTrue($this->checker(me: 5, rights: ['hrm.employee.read'], hrm: true)->canSeeSalary($target));
		// Neither salaries nor hrm enabled: admin or hierarchy.
		self::assertFalse($this->checker(me: 5)->canSeeSalary($target));
		self::assertTrue($this->checker(me: 5, admin: true)->canSeeSalary($target));
		self::assertTrue($this->checker(me: 5, subordinates: [6])->canSeeSalary($target));
		// A new user (id 0) counts as one under me: card.php tests $id == 0 next to the hierarchy.
		self::assertTrue($this->checker(me: 5, rights: ['salaries.read'], salaries: true)->canSeeSalary($this->user(id: 0)));
		self::assertTrue($this->checker(me: 5)->canSeeSalary($this->user(id: 0)));
		// External users never see salaries through the rights.
		self::assertFalse($this->checker(me: 5, rights: ['salaries.readall'], salaries: true, companyId: 10)->canSeeSalary($this->user(id: 6, companyId: 10)));
	}

	public function testEditRightDependsOnSelfOrOthers(): void
	{
		self::assertTrue($this->checker(me: 5, rights: ['user.self.creer'])->canEdit($this->user(id: 5)));
		self::assertFalse($this->checker(me: 5, rights: ['user.self.creer'])->canEdit($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.creer'])->canEdit($this->user(id: 6)));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.creer'], companyId: 10)->canEdit($this->user(id: 6, companyId: 11)));
	}

	public function testPasswordRights(): void
	{
		$other = $this->user(id: 6);
		self::assertFalse($this->checker(me: 5, rights: ['user.user.password'])->canSetPassword($other));
		self::assertTrue($this->checker(me: 5, admin: true, rights: ['user.user.password'])->canSetPassword($other));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.password'])->canSendPassword($other));
		self::assertTrue($this->checker(me: 5, rights: ['user.self.password'])->canSetPassword($this->user(id: 5)));
	}

	public function testDisableNeedsDeleteRightOrAdmin(): void
	{
		self::assertFalse($this->checker(me: 5)->canDisable($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.supprimer'])->canDisable($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, admin: true)->canDisable($this->user(id: 6)));
	}

	public function testGroupEditUsesAdvancedPermsWhenEnabled(): void
	{
		$other = $this->user(id: 6);
		self::assertFalse($this->checker(me: 5, rights: ['user.user.read'])->canEditGroups($other));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.creer'])->canEditGroups($other));
		self::assertTrue($this->checker(me: 5, admin: true)->canEditGroups($other));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.write'], advanced: true)->canEditGroups($other));
		self::assertTrue($this->checker(me: 5, rights: ['user.group_advance.write'], advanced: true)->canEditGroups($other));
		self::assertFalse($this->checker(me: 5, admin: true, companyId: 10)->canEditGroups($this->user(id: 6, companyId: 11)));
	}

	public function testGroupReadUsesAdvancedPermsWhenEnabled(): void
	{
		// card.php l.136-139
		$other = $this->user(id: 6);
		self::assertFalse($this->checker(me: 5)->canReadGroups($other));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.lire'])->canReadGroups($other));
		self::assertTrue($this->checker(me: 5, admin: true)->canReadGroups($other));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.read'], advanced: true)->canReadGroups($other));
		self::assertTrue($this->checker(me: 5, rights: ['user.group_advance.read'], advanced: true)->canReadGroups($other));
		self::assertFalse($this->checker(me: 5, admin: true, companyId: 10)->canReadGroups($this->user(id: 6, companyId: 11)));
	}

	public function testDocumentGenerationNeedsTheReadRight(): void
	{
		// card.php l.3321: the right, not the admin flag
		self::assertTrue($this->checker(me: 5, rights: ['user.user.read'])->canGenerateDocuments());
		self::assertFalse($this->checker(me: 5, admin: true)->canGenerateDocuments());
	}

	public function testCreateListAndInternal(): void
	{
		self::assertTrue($this->checker(me: 5, rights: ['user.user.write'])->canCreate());
		self::assertFalse($this->checker(me: 5, rights: ['user.user.write'], companyId: 10)->canCreate());
		self::assertFalse($this->checker(me: 5)->canCreate());
		self::assertTrue($this->checker(me: 5, rights: ['user.user.lire'])->canList());
		self::assertTrue($this->checker(me: 5, admin: true)->canList());
		self::assertFalse($this->checker(me: 5)->canList());
		self::assertTrue($this->checker(me: 5)->isInternal());
		self::assertFalse($this->checker(me: 5, companyId: 10)->isInternal());
	}

	public function testCloneRules(): void
	{
		$superadmin = new User(id: 1, entity: 0, login: 'root', admin: true);
		$admin = new User(id: 2, entity: 1, login: 'adm', admin: true);
		$plain = $this->user(id: 6);
		$writer = $this->checker(me: 5, rights: ['user.user.write']);
		self::assertTrue($writer->canClone($plain));
		self::assertFalse($writer->canClone($admin));
		self::assertFalse($writer->canClone($superadmin));
		self::assertTrue($this->checker(me: 5, admin: true)->canClone($admin));
		self::assertFalse($this->checker(me: 5, admin: true)->canClone($superadmin));
		self::assertTrue($this->checker(me: 5, admin: true, meEntity: 0)->canClone($superadmin));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.write'], transverse: true, currentEntity: 2)->canClone($plain));
		self::assertFalse($this->checker(me: 5)->canClone($plain));
		self::assertTrue($writer->canWriteUsers());
		self::assertFalse($this->checker(me: 5, admin: true)->canWriteUsers());
	}

	public function testModifyButtons(): void
	{
		$other = $this->user(id: 6);
		self::assertTrue($this->checker(me: 5, rights: ['user.user.write'])->canModify($other));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.write'], multicompany: true, currentEntity: 2)->canModify($other));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.write'], multicompany: true, meEntity: 0, currentEntity: 2)->canModify($other));
		$pwd = $this->checker(me: 5, admin: true, rights: ['user.user.password']);
		self::assertFalse($pwd->canModify($other));
		self::assertTrue($pwd->canModifyForPassword($other, false));
		self::assertFalse($pwd->canModifyForPassword($other, true));
	}

	public function testPasswordButtonsNeverForOneself(): void
	{
		$other = $this->user(id: 6);
		$pwd = $this->checker(me: 5, admin: true, rights: ['user.user.password', 'user.self.password']);
		self::assertTrue($pwd->canReinitPassword($other, false));
		self::assertFalse($pwd->canReinitPassword($other, true));
		self::assertFalse($pwd->canReinitPassword($this->user(id: 5), false));
		self::assertTrue($pwd->canSendNewPassword($other, false));
		self::assertFalse($pwd->canSendNewPassword($this->user(id: 5), false));
		self::assertFalse($pwd->canSendNewPassword(new User(id: 7, entity: 2, login: 'x'), false));
	}

	public function testEnableDisableDeleteButtons(): void
	{
		$enabled = $this->user(id: 6);
		$disabled = new User(id: 7, entity: 1, login: 'u7', status: 0);
		$admin = new User(id: 8, entity: 1, login: 'u8', admin: true);
		$deleter = $this->checker(me: 5, rights: ['user.user.delete']);
		self::assertTrue($deleter->canDeactivate($enabled));
		self::assertFalse($deleter->canDeactivate($disabled));
		self::assertTrue($deleter->canReactivate($disabled));
		self::assertFalse($deleter->canReactivate($enabled));
		self::assertTrue($deleter->canDelete($enabled));
		self::assertFalse($this->checker(me: 6, admin: true)->canDeactivate($enabled));
		self::assertFalse($this->checker(me: 6, admin: true)->canDelete($enabled));
		self::assertFalse($this->checker(me: 5)->canDelete($enabled));
		self::assertFalse($deleter->canDeleteAdminTarget($admin));
		self::assertTrue($this->checker(me: 5, admin: true)->canDeleteAdminTarget($admin));
		self::assertTrue($deleter->canDeleteAdminTarget($enabled));
	}

	public function testDeleteButtonShownForAdminTargetButDisabled(): void
	{
		$admin = new User(id: 8, entity: 1, login: 'u8', admin: true);
		$deleter = $this->checker(me: 5, rights: ['user.user.delete']);
		self::assertTrue($deleter->canSeeDeleteButton($admin));
		self::assertFalse($deleter->canDeleteAdminTarget($admin));
		self::assertFalse($deleter->canSeeDeleteButton($this->user(id: 5)));
		self::assertFalse($deleter->canSeeDeleteButton(new User(id: 7, entity: 2, login: 'x')));
	}

	public function testNonAdminCannotEditAdmin(): void
	{
		$other = $this->user(id: 6);
		self::assertFalse($this->checker(me: 5, rights: ['user.user.write'])->canEditAdminFlag($other));
		self::assertTrue($this->checker(me: 5, admin: true)->canEditAdminFlag($other));
		// Never for oneself, never for an external user (card.php l.520, l.2620-2626).
		self::assertFalse($this->checker(me: 5, admin: true)->canEditAdminFlag($this->user(id: 5)));
		self::assertFalse($this->checker(me: 5, admin: true)->canEditAdminFlag($this->user(id: 6, companyId: 10)));
		// A new user (id 0) gets the flag from an admin only (card.php l.1184).
		self::assertTrue($this->checker(me: 5, admin: true)->canEditAdminFlag(new User(id: 0, entity: 1, login: '')));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.write'])->canEditAdminFlag(new User(id: 0, entity: 1, login: '')));
	}

	public function testCredentialsOfAnAdminAreProtected(): void
	{
		$plain = $this->user(id: 6);
		$admin = new User(id: 8, entity: 1, login: 'u8', admin: true);
		$superadmin = new User(id: 9, entity: 0, login: 'root', admin: true);
		$writer = $this->checker(me: 5, rights: ['user.user.write', 'user.user.password']);
		$entityAdmin = $this->checker(me: 5, admin: true, rights: ['user.user.password']);
		$superAdmin = $this->checker(me: 5, admin: true, rights: ['user.user.password'], meEntity: 0);

		// A non-admin never changes the password, login or email of an admin
		self::assertTrue($writer->canChangeCredentials($plain));
		self::assertFalse($writer->canChangeCredentials($admin));
		self::assertFalse($writer->canSendNewPassword($admin, false));
		self::assertTrue($writer->canSendNewPassword($plain, false));
		// An admin of an entity never changes those of a superadmin, nor his admin flag
		self::assertTrue($entityAdmin->canChangeCredentials($admin));
		self::assertFalse($entityAdmin->canChangeCredentials($superadmin));
		self::assertFalse($entityAdmin->canReinitPassword($superadmin, false));
		self::assertFalse($entityAdmin->canSendNewPassword($superadmin, false));
		self::assertFalse($entityAdmin->canModifyForPassword($superadmin, false));
		self::assertFalse($entityAdmin->canEditAdminFlag($superadmin));
		self::assertTrue($entityAdmin->canEditAdminFlag($admin));
		// A superadmin does
		self::assertTrue($superAdmin->canChangeCredentials($superadmin));
		self::assertTrue($superAdmin->canReinitPassword($superadmin, false));
		self::assertTrue($superAdmin->canEditAdminFlag($superadmin));
		// Oneself always
		self::assertTrue($this->checker(me: 9, admin: true)->canChangeCredentials($superadmin));
	}

	public function testOnlyAnAdminOnAnotherUserLinksTheCompany(): void
	{
		self::assertTrue($this->checker(me: 5, admin: true)->canLinkCompany($this->user(id: 6)));
		self::assertFalse($this->checker(me: 5, admin: true)->canLinkCompany($this->user(id: 5)));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.write'])->canLinkCompany($this->user(id: 6)));
	}

	public function testOnlyAdminCanDeleteAdmin(): void
	{
		$admin = new User(id: 8, entity: 1, login: 'u8', admin: true);
		$superadmin = new User(id: 9, entity: 0, login: 'root', admin: true);
		self::assertFalse($this->checker(me: 5, rights: ['user.user.delete'])->canDelete($admin));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.delete'])->canDelete($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, admin: true)->canDelete($admin));
		// Only a superadmin deletes a superadmin (card.php l.264-267).
		self::assertFalse($this->checker(me: 5, admin: true)->canDelete($superadmin));
		self::assertTrue($this->checker(me: 5, admin: true, meEntity: 0)->canDelete($superadmin));
		self::assertFalse($this->checker(me: 5, admin: true)->canDelete($this->user(id: 5)));
		self::assertFalse($this->checker(me: 5)->canDelete($this->user(id: 6)));
	}

	public function testCannotDisableSelf(): void
	{
		$admin = new User(id: 8, entity: 1, login: 'u8', admin: true);
		self::assertFalse($this->checker(me: 5, admin: true)->canDisable($this->user(id: 5)));
		self::assertFalse($this->checker(me: 5, admin: true)->canEnable($this->user(id: 5)));
		self::assertTrue($this->checker(me: 5, admin: true)->canEnable($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.delete'])->canEnable($this->user(id: 6)));
		self::assertFalse($this->checker(me: 5)->canEnable($this->user(id: 6)));
		// Only an admin disables an admin (card.php l.218-221).
		self::assertFalse($this->checker(me: 5, rights: ['user.user.delete'])->canDisable($admin));
		self::assertTrue($this->checker(me: 5, admin: true)->canDisable($admin));
	}

	public function testEditLoginOnlyAdminAndNoLdap(): void
	{
		$other = $this->user(id: 6);
		self::assertTrue($this->checker(me: 5, admin: true)->canEditLogin($other));
		self::assertFalse($this->checker(me: 5, admin: true)->canEditLogin($other, true));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.write'])->canEditLogin($other));
		self::assertFalse($this->checker(me: 5, rights: ['user.self.write'])->canEditLogin($this->user(id: 5)));
		// Creation form: the login is always typed, unless it comes from LDAP (card.php l.1178).
		$new = new User(id: 0, entity: 1, login: '');
		self::assertTrue($this->checker(me: 5, rights: ['user.user.write'])->canEditLogin($new));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.write'])->canEditLogin($new, true));
	}

	public function testRightsRulesWithAdvancedPerms(): void
	{
		$other = $this->user(id: 6);
		$self = $this->user(id: 5);
		// Without advanced permissions (perms.php l.67-69).
		self::assertTrue($this->checker(me: 5, rights: ['user.user.read'])->canReadRights($other));
		self::assertFalse($this->checker(me: 5)->canReadRights($other));
		self::assertTrue($this->checker(me: 5)->canReadRights($self));
		self::assertTrue($this->checker(me: 5, admin: true)->canEditRights($other));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.write'])->canEditRights($other));
		self::assertFalse($this->checker(me: 5, rights: ['user.self_advance.writeperms'])->canEditRights($self));
		// With advanced permissions (perms.php l.71-75 and l.84).
		self::assertFalse($this->checker(me: 5, rights: ['user.user.read'], advanced: true)->canReadRights($other));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.read', 'user.user_advance.readperms'], advanced: true)->canReadRights($other));
		self::assertTrue($this->checker(me: 5, admin: true, advanced: true)->canReadRights($other));
		self::assertFalse($this->checker(me: 5, advanced: true)->canReadRights($self));
		self::assertTrue($this->checker(me: 5, rights: ['user.self_advance.readperms'], advanced: true)->canReadRights($self));
		self::assertTrue($this->checker(me: 5, admin: true, advanced: true)->canReadRights($self));
		self::assertTrue($this->checker(me: 5, rights: ['user.self_advance.writeperms'], advanced: true)->canEditRights($self));
		self::assertFalse($this->checker(me: 5, rights: ['user.self_advance.writeperms'], advanced: true)->canEditRights($other));
		self::assertFalse($this->checker(me: 5, advanced: true)->canEditRights($self));
		// External users only on their company.
		self::assertFalse($this->checker(me: 5, rights: ['user.user.read'], companyId: 10)->canReadRights($other));
	}

	public function testHrApiKeyAndMassActions(): void
	{
		self::assertFalse($this->checker(me: 5)->canReadHr());
		self::assertTrue($this->checker(me: 5, rights: ['hrm.read_personal_information.read'])->canReadHr());
		self::assertTrue($this->checker(me: 5, rights: ['hrm.write_personal_information.write'])->canReadHr());
		self::assertTrue($this->checker(me: 5)->canReadApiKey($this->user(id: 5)));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.write'])->canReadApiKey($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, admin: true)->canReadApiKey($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, rights: ['user.user.write'])->canUseMassActions());
		self::assertTrue($this->checker(me: 5, admin: true)->canUseMassActions());
		self::assertFalse($this->checker(me: 5, rights: ['user.user.read'])->canUseMassActions());
		// list.php l.227: forced off in multicompany transverse mode for a user of an entity.
		self::assertFalse($this->checker(me: 5, admin: true, multicompany: true, transverse: true)->canUseMassActions());
		self::assertTrue($this->checker(me: 5, admin: true, multicompany: true, transverse: true, meEntity: 0)->canUseMassActions());
		self::assertTrue($this->checker(me: 5, admin: true, transverse: true)->canUseMassActions());
	}

	/**
	 * @param list<string> $rights
	 * @param list<int>    $subordinates
	 */
	private function checker(
		int $me,
		bool $admin = false,
		array $rights = [],
		int $companyId = 0,
		bool $advanced = false,
		bool $salaries = false,
		bool $hrm = false,
		array $subordinates = [],
		bool $multicompany = false,
		bool $transverse = false,
		int $currentEntity = 1,
		int $meEntity = 1
	): UserPermissionChecker {
		return new UserPermissionChecker(
			new RightsCurrentUser($me, $admin, $rights, $companyId, $meEntity),
			$advanced,
			$salaries,
			$hrm,
			$subordinates,
			$multicompany,
			$transverse,
			$currentEntity
		);
	}

	private function user(int $id, ?int $companyId = null): User
	{
		return new User(id: $id, entity: 1, login: 'u'.$id, companyId: $companyId);
	}
}
