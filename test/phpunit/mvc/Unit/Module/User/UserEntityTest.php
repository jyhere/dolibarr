<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Entity\Visibility;
use PHPUnit\Framework\TestCase;

final class UserEntityTest extends TestCase
{
	private const PUBLIC_KEYS = ['id', 'entity', 'login', 'admin', 'employee', 'status', 'lastname', 'firstname', 'gender', 'civility', 'job', 'email', 'officePhone', 'officeFax', 'userMobile', 'photo', 'lang', 'color', 'dateStartValidity', 'dateEndValidity', 'hasApiKey'];
	private const SAME_USER_EXTRA = ['personalEmail', 'personalMobile', 'address', 'zip', 'town', 'stateId', 'countryId', 'birth', 'birthPlace', 'signature', 'dateLastLogin', 'datePreviousLogin', 'notePublic', 'notePrivate'];
	private const ADMIN_EXTRA = ['companyId', 'contactId', 'memberId', 'supervisorId', 'expenseValidatorId', 'holidayValidatorId', 'refEmployee', 'refExt', 'dateCreated', 'dateModified', 'salary', 'salaryExtra', 'thm', 'tjm', 'weeklyHours', 'dateEmployment', 'dateEmploymentEnd', 'accountancyCode', 'warehouseId', 'nationalRegistrationNumber', 'isLdapManaged', 'forcePasswordChange'];

	public function testToArrayPublicNeverExposesSecretsOrPrivateFields(): void
	{
		$a = $this->user(notePrivate: 'secret')->toArray(Visibility::Public);
		foreach (['pass', 'passCrypted', 'apiKey', 'openid', 'ldapSid', 'notePrivate', 'salary', 'companyId'] as $k) {
			self::assertArrayNotHasKey($k, $a);
		}
		self::assertSame(['id' => 1, 'entity' => 1, 'login' => 'admin'], array_intersect_key($a, array_flip(['id', 'entity', 'login'])));
	}

	public function testToArrayDefaultIsPublic(): void
	{
		self::assertSame($this->user()->toArray(Visibility::Public), $this->user()->toArray());
	}

	public function testToArrayKeySetsPerVisibilityLevel(): void
	{
		$u = $this->user();
		$public = array_keys($u->toArray(Visibility::Public));
		$same = array_keys($u->toArray(Visibility::SameUser));
		$admin = array_keys($u->toArray(Visibility::Admin));
		self::assertSame(self::PUBLIC_KEYS, $public);
		self::assertSame([...self::PUBLIC_KEYS, ...self::SAME_USER_EXTRA], $same);
		self::assertSame([...self::PUBLIC_KEYS, ...self::SAME_USER_EXTRA, ...self::ADMIN_EXTRA], $admin);
		foreach ($admin as $k) {
			self::assertDoesNotMatchRegularExpression('/^(pass|apiKey$|openid|ldapSid)/i', $k);
		}
	}

	public function testToArrayAdminExposesSalaryAndLinks(): void
	{
		self::assertArrayHasKey('salary', $this->user()->toArray(Visibility::Admin));
		self::assertArrayHasKey('companyId', $this->user()->toArray(Visibility::Admin));
	}

	public function testToArraySerializesDatesAsIso8601OrNull(): void
	{
		$a = $this->user(
			dateStartValidity: new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))
		)->toArray(Visibility::Public);
		self::assertSame('2026-01-01T00:00:00+00:00', $a['dateStartValidity']);
		self::assertNull($a['dateEndValidity']);
	}

	public function testFullNameIsFirstnameThenLastname(): void
	{
		self::assertSame('Jane Doe', $this->user(firstname: 'Jane', lastname: 'Doe')->fullName());
		self::assertSame('Doe', $this->user(lastname: 'Doe')->fullName());
	}

	public function testIsEnabledAndIsExternal(): void
	{
		self::assertTrue($this->user(status: 1)->isEnabled());
		self::assertFalse($this->user(status: 0)->isEnabled());
		self::assertTrue($this->user(companyId: 5)->isExternal());
		self::assertFalse($this->user(companyId: null)->isExternal());
		self::assertFalse($this->user(companyId: 0)->isExternal());
	}

	public function testValidityPeriodBoundsAreInclusive(): void
	{
		$u = $this->user(
			dateStartValidity: new \DateTimeImmutable('2026-01-01 00:00:00'),
			dateEndValidity: new \DateTimeImmutable('2026-12-31 00:00:00')
		);
		self::assertTrue($u->isWithinValidityPeriod(new \DateTimeImmutable('2026-01-01 00:00:00')));
		self::assertTrue($u->isWithinValidityPeriod(new \DateTimeImmutable('2026-12-31 23:59:59')));
		self::assertFalse($u->isWithinValidityPeriod(new \DateTimeImmutable('2025-12-31 23:59:59')));
		self::assertFalse($u->isWithinValidityPeriod(new \DateTimeImmutable('2027-01-01 00:00:00')));
		self::assertTrue($this->user()->isWithinValidityPeriod(new \DateTimeImmutable('2027-01-01')));
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function user(mixed ...$overrides): User
	{
		/** @var array<string, mixed> $args */
		$args = array_merge(['id' => 1, 'entity' => 1, 'login' => 'admin'], $overrides);

		return new User(...$args);
	}
}
