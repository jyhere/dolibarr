<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Module\User\Controller\LoginController;
use PHPUnit\Framework\TestCase;

final class LoginRedirectTest extends TestCase
{
	public function testSafeBackToPageRejectsOtherHostsAndProtocolRelative(): void
	{
		self::assertNull(LoginController::safeBackToPage('https://evil.example/x', ''));
		self::assertNull(LoginController::safeBackToPage('//evil.example/x', ''));
		self::assertSame('/user/1', LoginController::safeBackToPage('/user/1', ''));
		self::assertNull(LoginController::safeBackToPage('/user/1', '/dolibarr'));
		self::assertSame('/dolibarr/user/1', LoginController::safeBackToPage('/dolibarr/user/1', '/dolibarr'));
	}

	public function testSafeBackToPageRejectsEmptyBackslashesAndControlCharacters(): void
	{
		self::assertNull(LoginController::safeBackToPage(null, ''));
		self::assertNull(LoginController::safeBackToPage('', ''));
		self::assertNull(LoginController::safeBackToPage('/\\evil.example/x', ''));
		self::assertNull(LoginController::safeBackToPage('user/1', ''));
		self::assertNull(LoginController::safeBackToPage("/user/1\r\nSet-Cookie: x=1", ''));
		self::assertNull(LoginController::safeBackToPage('/dolibarrevil/x', '/dolibarr'));
		self::assertSame('/dolibarr', LoginController::safeBackToPage('/dolibarr', '/dolibarr'));
		self::assertSame('/user/card.php?id=1&mainmenu=home', LoginController::safeBackToPage('/user/card.php?id=1&mainmenu=home', ''));
	}
}
