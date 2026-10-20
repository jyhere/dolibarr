<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\LegacyHooks;
use PHPUnit\Framework\TestCase;

final class LegacyHooksTest extends TestCase
{
	public function testHooksWithoutModulesReturnCodeZero(): void
	{
		$h = new LegacyHooks($GLOBALS['hookmanager']);
		$h->init(['usercard']);
		$o = null;
		$a = 'view';
		$r = $h->execute('formObjectOptions', ['id' => 1], $o, $a);
		self::assertSame(0, $r->code);
		self::assertSame('', $r->print);
	}
}
