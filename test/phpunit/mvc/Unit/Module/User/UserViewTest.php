<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Entity\UserView;
use Dolibarr\Module\User\Entity\Visibility;
use PHPUnit\Framework\TestCase;

final class UserViewTest extends TestCase
{
	public function testToArrayDelegatesToTheVisibilityPolicy(): void
	{
		$user = new User(id: 1, entity: 1, login: 'admin', salary: 1000.0);
		self::assertSame($user->toArray(Visibility::SameUser), (new UserView($user, Visibility::SameUser))->toArray());
		self::assertSame(1000.0, (new UserView($user, Visibility::Admin))->toArray()['salary']);
	}

	public function testSalaryKeysRemovedWithoutSalaryPermission(): void
	{
		$data = (new UserView(new User(id: 1, entity: 1, login: 'admin', salary: 1000.0), Visibility::Admin, false))->toArray();
		foreach (UserView::SALARY_KEYS as $key) {
			self::assertArrayNotHasKey($key, $data);
		}
		self::assertSame('admin', $data['login']);
	}
}
