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

	public function testGroupReadUsesAdvancedPermsWhenEnabled(): void
	{
		self::assertTrue($this->checker(me: 5, rights: ['user.user.read'])->canReadGroups($this->user(id: 6)));
		self::assertFalse($this->checker(me: 5, rights: ['user.user.read'], advanced: true)->canReadGroups($this->user(id: 6)));
		self::assertTrue($this->checker(me: 5, rights: ['user.group_advance.read'], advanced: true)->canReadGroups($this->user(id: 6)));
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
		array $subordinates = []
	): UserPermissionChecker {
		return new UserPermissionChecker(new RightsCurrentUser($me, $admin, $rights, $companyId), $advanced, $salaries, $hrm, $subordinates);
	}

	private function user(int $id, ?int $companyId = null): User
	{
		return new User(id: $id, entity: 1, login: 'u'.$id, companyId: $companyId);
	}
}
