<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\LegacyContext;
use PHPUnit\Framework\TestCase;

final class LegacyContextTest extends TestCase
{
	public function testAuthModeReadsTheSession(): void
	{
		$previous = $_SESSION ?? null;
		try {
			$_SESSION = ['dol_authmode' => 'dolibarr'];
			self::assertSame('dolibarr', LegacyContext::fromGlobals()->authMode());
			$_SESSION = ['dol_authmode' => ['not a string']];
			self::assertSame('', LegacyContext::fromGlobals()->authMode());
			$_SESSION = [];
			self::assertSame('', LegacyContext::fromGlobals()->authMode());
		} finally {
			if ($previous === null) {
				unset($_SESSION);
			} else {
				$_SESSION = $previous;
			}
		}
	}
}
