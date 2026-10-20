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
