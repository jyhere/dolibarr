<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyTriggers;
use PHPUnit\Framework\TestCase;

final class LegacyTriggersTest extends TestCase
{
	public function testTriggersRunReturnsNonNegativeForUserLogout(): void
	{
		$triggers = new LegacyTriggers(LegacyContext::fromGlobals());
		self::assertGreaterThanOrEqual(0, $triggers->run('USER_LOGOUT', $GLOBALS['user'], $GLOBALS['user']));
	}
}
