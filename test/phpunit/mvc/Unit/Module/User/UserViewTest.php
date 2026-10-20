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
		$u = new User(id: 1, entity: 1, login: 'admin', salary: 1000.0);
		self::assertSame($u->toArray(Visibility::SameUser), (new UserView($u, Visibility::SameUser))->toArray());
		self::assertSame(1000.0, (new UserView($u, Visibility::Admin))->toArray()['salary']);
	}

	public function testSalaryKeysRemovedWithoutSalaryPermission(): void
	{
		$a = (new UserView(new User(id: 1, entity: 1, login: 'admin', salary: 1000.0), Visibility::Admin, false))->toArray();
		foreach (UserView::SALARY_KEYS as $key) {
			self::assertArrayNotHasKey($key, $a);
		}
		self::assertSame('admin', $a['login']);
	}
}
