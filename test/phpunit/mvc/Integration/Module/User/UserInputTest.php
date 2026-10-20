<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Http\InputFilter;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyInputFilter;
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
			'national_registration_number' => '123',
		]);
		$ctx = LegacyContext::fromGlobals();
		$config = new LegacyConfig($ctx);

		$input = UserInput::fromRequest($request, $this->checker(admin: true), null, $config);
		$entity = $input->toEntity(null, 1);

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
		self::assertSame('secret', $input->password);
		self::assertSame([3, 4], $input->categoryIds);
		self::assertTrue($input->forcePasswordChange);
		// Salary: the fake checker (salaries module on, no right) denies it.
		self::assertNull($entity->salary);
		self::assertNull($entity->thm);
		self::assertFalse($entity->hasApiKey);
		self::assertSame('123', $entity->nationalRegistrationNumber);
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

	public function testNonAdminKeepsAdminFlagAndSelfEditKeepsLogin(): void
	{
		$request = GlobalsRequest::create('POST', '/user', [], ['lastname' => 'Doe', 'login' => 'hacked', 'admin' => '0', 'national_registration_number' => '999']);
		$config = new LegacyConfig(LegacyContext::fromGlobals());

		$other = new User(id: 9, entity: 1, login: 'old', admin: true, nationalRegistrationNumber: '111');
		$entity = UserInput::fromRequest($request, $this->checker(rights: ['user.user.write']), $other, $config)->toEntity($other, 1);
		self::assertTrue($entity->admin);
		self::assertSame('old', $entity->login);
		self::assertSame('999', $entity->nationalRegistrationNumber);

		$self = new User(id: 5, entity: 1, login: 'me');
		self::assertSame('me', UserInput::fromRequest($request, $this->checker(), $self, $config)->toEntity($self, 1)->login);
	}

	public function testLdapManagedLoginIsKeptEvenForAnAdmin(): void
	{
		$request = GlobalsRequest::create('POST', '/user', [], ['lastname' => 'Doe', 'login' => 'hacked']);
		$config = new LegacyConfig(LegacyContext::fromGlobals());

		$ldap = new User(id: 9, entity: 1, login: 'old', isLdapManaged: true);
		$entity = UserInput::fromRequest($request, $this->checker(admin: true), $ldap, $config)->toEntity($ldap, 1);
		self::assertSame('old', $entity->login);
		self::assertTrue($entity->isLdapManaged);

		$local = new User(id: 9, entity: 1, login: 'old');
		self::assertSame('hacked', UserInput::fromRequest($request, $this->checker(admin: true), $local, $config)->toEntity($local, 1)->login);
	}

	public function testContactIdOnUpdateSetsContactAndCompany(): void
	{
		$request = GlobalsRequest::create('POST', '/user', [], ['lastname' => 'Doe', 'login' => 'jdoe', 'contactid' => '12', 'socid' => '7']);
		$config = new LegacyConfig(LegacyContext::fromGlobals());
		$base = new User(id: 9, entity: 1, login: 'old');

		$entity = UserInput::fromRequest($request, $this->checker(), $base, $config)->toEntity($base, 1);

		self::assertSame(12, $entity->contactId);
		self::assertSame(7, $entity->companyId);
	}

	public function testImpossibleOrHugeDatesAreNull(): void
	{
		$request = GlobalsRequest::create('POST', '/user', [], [
			'lastname' => 'Doe', 'login' => 'jdoe',
			'dateofbirthday' => '30', 'dateofbirthmonth' => '2', 'dateofbirthyear' => '1980',
			'dateemploymentday' => '1', 'dateemploymentmonth' => '1', 'dateemploymentyear' => '10000',
		]);
		$entity = UserInput::fromRequest($request, $this->checker(), null, new LegacyConfig(LegacyContext::fromGlobals()))->toEntity(null, 1);

		self::assertNull($entity->birth);
		self::assertNull($entity->dateEmployment);
	}

	public function testExtraFieldsAreMergedOnUpdateAndErrorIsKept(): void
	{
		$request = GlobalsRequest::create('POST', '/user', [], ['lastname' => 'Doe', 'login' => 'jdoe']);
		$filter = new class implements InputFilter {
			public ?bool $lastUpdate = null;

			public function filter(string $key, string $filter, mixed $default = null, int $method = 0): mixed
			{
				return (new LegacyInputFilter())->filter($key, $filter, $default, $method);
			}

			public function isSet(string $key): bool
			{
				return (new LegacyInputFilter())->isSet($key);
			}

			public function optionals(string $element, bool $update = false): array
			{
				$this->lastUpdate = $update;

				return ['options_b' => 'new', InputFilter::OPTIONALS_ERROR => 'required'];
			}
		};
		$request = $request->withInputFilter($filter);
		$config = new LegacyConfig(LegacyContext::fromGlobals());
		$base = new User(id: 9, entity: 1, login: 'old', extraFields: ['options_a' => 'x', 'options_b' => 'old']);

		$input = UserInput::fromRequest($request, $this->checker(), $base, $config);

		self::assertTrue($filter->lastUpdate);
		self::assertSame('required', $input->extraFieldsError);
		self::assertSame(['options_a' => 'x', 'options_b' => 'new'], $input->toEntity($base, 1)->extraFields);
		self::assertNull(UserInput::fromRequest($request->withInputFilter(new LegacyInputFilter()), $this->checker(), $base, $config)->extraFieldsError);
	}

	public function testLegacyOptionalsReturnsAnArrayWithoutDefinitions(): void
	{
		GlobalsRequest::create('POST', '/user', [], ['options_zzz' => 'v']);

		self::assertArrayNotHasKey('options_zzz', (new LegacyInputFilter())->optionals('user', true));
		self::assertArrayNotHasKey('options_zzz', (new LegacyInputFilter())->optionals('user'));
	}

	/**
	 * @param list<string> $rights
	 */
	private function checker(bool $admin = false, array $rights = []): UserPermissionChecker
	{
		return new UserPermissionChecker(new RightsCurrentUser(5, $admin, $rights), false, true);
	}
}
