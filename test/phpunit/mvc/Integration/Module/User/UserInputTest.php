<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserInput;
use Dolibarr\Tests\Integration\Fixture\GlobalsRequest;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

final class UserInputTest extends TestCase
{
	/** @var array<mixed> */
	private array $get = [];

	/** @var array<mixed> */
	private array $post = [];

	protected function setUp(): void
	{
		$this->get = $_GET;
		$this->post = $_POST;
	}

	protected function tearDown(): void
	{
		$_GET = $this->get;
		$_POST = $this->post;
	}

	public function testFromRequestReadsCardFieldsWithLegacyFilters(): void
	{
		$request = GlobalsRequest::create('POST', '/user', [], [
			'lastname' => '<b>Doe</b>',
			'firstname' => 'John',
			'login' => 'jdoe',
			'email' => ' j d@exa mple.fr ',
			'civility_code' => 'MR',
			'gender' => 'man',
			'default_lang' => 'fr_FR',
			'zipcode' => '75001',
			'town' => 'Paris',
			'country_id' => '1',
			'office_fax' => '0102',
			'accountancy_code' => '421',
			'note_public' => '<b>public</b>',
			'note_private' => 'private',
			'signature' => '<i>sig</i>',
			'color' => '#aabbcc',
			'admin' => '1',
			'employee' => '1',
			'fk_user' => '0',
			'fk_user_holiday_validator' => '5',
			'salary' => '1 234,5',
			'thm' => '12',
			'password' => 'secret',
			'usercats' => ['3', '4'],
			'forcepasswordchange' => '1',
			'dateofbirthday' => '5',
			'dateofbirthmonth' => '3',
			'dateofbirthyear' => '1980',
			'dateemploymentday' => '5',
			'api_key' => 'ignored',
		]);
		$ctx = LegacyContext::fromGlobals();
		$config = new LegacyConfig($ctx);

		$denied = UserInput::fromRequest($request, $this->checker(admin: true), null, $config);
		$entity = $denied->toEntity(null, 1);

		self::assertSame('Doe', $entity->lastname);
		self::assertSame('John', $entity->firstname);
		self::assertSame('jdoe', $entity->login);
		self::assertSame('jd@example.fr', $entity->email);
		self::assertSame('MR', $entity->civility);
		self::assertSame('fr_FR', $entity->lang);
		self::assertSame('75001', $entity->zip);
		self::assertSame(1, $entity->countryId);
		self::assertSame('0102', $entity->officeFax);
		self::assertSame('421', $entity->accountancyCode);
		self::assertSame('<b>public</b>', $entity->notePublic);
		self::assertSame('<i>sig</i>', $entity->signature);
		self::assertSame('aabbcc', $entity->color);
		self::assertTrue($entity->admin);
		self::assertTrue($entity->employee);
		self::assertNull($entity->supervisorId);
		self::assertSame(5, $entity->holidayValidatorId);
		self::assertSame('1980-03-05', $entity->birth?->format('Y-m-d'));
		self::assertNull($entity->dateEmployment, 'a date needs day, month and year');
		self::assertSame(1, $entity->status);
		self::assertSame(1, $entity->entity);
		self::assertSame('secret', $denied->password);
		self::assertSame([3, 4], $denied->categoryIds);
		self::assertTrue($denied->forcePasswordChange);
		// Salary: the fake checker (salaries module on, no right) denies it.
		self::assertNull($entity->salary);
		self::assertNull($entity->thm);
		self::assertFalse($entity->hasApiKey);
	}

	public function testSalaryIsReadWhenAllowedAndKeptFromBaseOtherwise(): void
	{
		$request = GlobalsRequest::create('POST', '/user', [], ['lastname' => 'Doe', 'login' => 'jdoe', 'salary' => '1 234,5', 'weeklyhours' => '35', 'admin' => '1']);
		$config = new LegacyConfig(LegacyContext::fromGlobals());

		$allowed = UserInput::fromRequest($request, $this->checker(rights: ['salaries.readall']), null, $config)->toEntity(null, 1);
		self::assertSame(1234.5, $allowed->salary);
		self::assertSame(35.0, $allowed->weeklyHours);
		self::assertFalse($allowed->admin, 'a non admin cannot set the admin flag');

		$base = new User(id: 9, entity: 1, login: 'old', admin: true, salary: 10.0, lastname: 'Old', notePublic: 'keep', extraFields: ['options_a' => 'x']);
		$kept = UserInput::fromRequest($request, $this->checker(), $base, $config)->toEntity($base, 1);
		self::assertSame(10.0, $kept->salary);
		self::assertTrue($kept->admin);
		self::assertSame('Doe', $kept->lastname);
		self::assertSame(9, $kept->id);
		self::assertSame('keep', $kept->notePublic, 'notes are not read on update');
		self::assertSame(['options_a' => 'x'], $kept->extraFields);
	}

	public function testUpdateKeepsLoginWithoutRightAndGatesOptionalFields(): void
	{
		$request = GlobalsRequest::create('POST', '/user', [], ['lastname' => 'Doe', 'login' => 'hacked', 'contactid' => '0', 'socid' => '7']);
		$config = new LegacyConfig(LegacyContext::fromGlobals());
		$base = new User(id: 9, entity: 1, login: 'old', refEmployee: 'E1', warehouseId: 4, companyId: 2, contactId: 3);

		$entity = UserInput::fromRequest($request, $this->checker(), $base, $config)->toEntity($base, 1);

		self::assertSame('old', $entity->login);
		self::assertSame('E1', $entity->refEmployee, 'ref_employee absent from the form is kept');
		self::assertSame(4, $entity->warehouseId, 'fk_warehouse needs the stock module');
		self::assertSame(7, $entity->companyId);
		self::assertNull($entity->contactId);
	}

	/**
	 * @param list<string> $rights
	 */
	private function checker(bool $admin = false, array $rights = []): UserPermissionChecker
	{
		return new UserPermissionChecker(new RightsCurrentUser(5, $admin, $rights), false, true);
	}
}
