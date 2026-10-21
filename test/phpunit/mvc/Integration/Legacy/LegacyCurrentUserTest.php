<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\LegacyCurrentUser;
use PHPUnit\Framework\TestCase;

final class LegacyCurrentUserTest extends TestCase
{
	public function testCurrentUserAdminHasUserReadRight(): void
	{
		$currentUser = new LegacyCurrentUser($GLOBALS['user']);
		self::assertTrue($currentUser->isAdmin());
		self::assertTrue($currentUser->hasRight('user', 'user', 'lire'));
		self::assertSame(1, $currentUser->id());
	}
}
