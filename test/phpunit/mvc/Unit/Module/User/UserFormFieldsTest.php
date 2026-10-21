<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Module\User\Service\UserFormFields;
use Dolibarr\Tests\Unit\Module\User\Fixture\ArrayConfig;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

final class UserFormFieldsTest extends TestCase
{
	public function testRowsFollowTheSetup(): void
	{
		$me = new RightsCurrentUser(5, true, ['categorie.read']);

		$off = UserFormFields::resolve(new ArrayConfig(), $me, '', false, false);
		self::assertFalse($off->accountancyCode, 'accounting off');
		self::assertFalse($off->color, 'agenda off');
		self::assertFalse($off->warehouse, 'stock off');
		self::assertFalse($off->categories, 'category off');
		self::assertFalse($off->civility);
		self::assertFalse($off->language);
		self::assertTrue($off->state);
		self::assertFalse($off->forcePasswordChange);

		$stockWithoutDefault = UserFormFields::resolve(new ArrayConfig([], 0, ',', ['stock']), $me, '', false, false);
		self::assertFalse($stockWithoutDefault->warehouse, 'stock on, MAIN_DEFAULT_WAREHOUSE_USER unset');

		$on = UserFormFields::resolve(
			new ArrayConfig(['MAIN_DEFAULT_WAREHOUSE_USER' => '1', 'MAIN_MULTILANGS' => '1', 'MAIN_USE_TITLE_FOR_USER' => '1', 'USER_DISABLE_STATE' => '0'], 0, ',', ['stock', 'accounting', 'agenda', 'category', 'expensereport', 'holiday']),
			$me,
			'dolibarr',
			true,
			true
		);
		self::assertTrue($on->warehouse);
		self::assertTrue($on->accountancyCode);
		self::assertTrue($on->color);
		self::assertTrue($on->categories);
		self::assertTrue($on->language);
		self::assertTrue($on->civility);
		self::assertTrue($on->state, "USER_DISABLE_STATE '0' does not hide the state (!getDolGlobalString())");
		self::assertTrue($on->forcePasswordChange);
		self::assertTrue($on->expenseValidator);
		self::assertTrue($on->salary);
		self::assertTrue($on->admin);
		self::assertFalse($on->superadmin, 'multicompany off');
		self::assertFalse(UserFormFields::resolve(new ArrayConfig(['USER_DISABLE_STATE' => '1']), $me, '', false, false)->state);
	}

	public function testTagsNeedTheReadRightAndValidatorsTheHierarchy(): void
	{
		$config = new ArrayConfig([], 0, ',', ['category', 'expensereport', 'holiday']);

		self::assertFalse(UserFormFields::resolve($config, new RightsCurrentUser(5, false, ['user.user.write']), '', false, false)->categories);
		$noHierarchy = UserFormFields::resolve($config, new RightsCurrentUser(5, false, ['categorie.read']), '', false, false, false);
		self::assertTrue($noHierarchy->categories);
		self::assertFalse($noHierarchy->hierarchy);
		self::assertFalse($noHierarchy->expenseValidator);
		self::assertFalse($noHierarchy->holidayValidator);
		self::assertSame(array_keys(get_class_vars(UserFormFields::class)), array_keys($noHierarchy->toArray()));
	}
}
