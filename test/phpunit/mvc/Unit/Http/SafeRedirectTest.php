<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Http;

use Dolibarr\Core\Http\SafeRedirect;
use PHPUnit\Framework\TestCase;

final class SafeRedirectTest extends TestCase
{
	public function testRejectsOtherHostsAndProtocolRelative(): void
	{
		self::assertNull(SafeRedirect::relativePath('https://evil.example/x', ''));
		self::assertNull(SafeRedirect::relativePath('//evil.example/x', ''));
		self::assertSame('/user/1', SafeRedirect::relativePath('/user/1', ''));
		self::assertNull(SafeRedirect::relativePath('/user/1', '/dolibarr'));
		self::assertSame('/dolibarr/user/1', SafeRedirect::relativePath('/dolibarr/user/1', '/dolibarr'));
	}

	public function testRejectsEmptyBackslashesAndControlCharacters(): void
	{
		self::assertNull(SafeRedirect::relativePath(null, ''));
		self::assertNull(SafeRedirect::relativePath('', ''));
		self::assertNull(SafeRedirect::relativePath('/\\evil.example/x', ''));
		self::assertNull(SafeRedirect::relativePath('user/1', ''));
		self::assertNull(SafeRedirect::relativePath('evil.example/x', ''));
		self::assertNull(SafeRedirect::relativePath("/user/1\r\nSet-Cookie: x=1", ''));
		self::assertNull(SafeRedirect::relativePath('/dolibarrevil/x', '/dolibarr'));
		self::assertSame('/dolibarr', SafeRedirect::relativePath('/dolibarr', '/dolibarr'));
		self::assertSame('/user/card.php?id=1&mainmenu=home', SafeRedirect::relativePath('/user/card.php?id=1&mainmenu=home', ''));
	}
}
