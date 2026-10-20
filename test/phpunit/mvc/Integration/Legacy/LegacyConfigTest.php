<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use PHPUnit\Framework\TestCase;

final class LegacyConfigTest extends TestCase
{
	public function testConfigReadsGlobals(): void
	{
		$ctx = LegacyContext::fromGlobals();
		$c = new LegacyConfig($ctx);
		self::assertSame($GLOBALS['conf']->entity, $c->entity());
		self::assertTrue($c->isModuleEnabled('user'));
		self::assertFalse($c->isModuleEnabled('notamodule'));
	}

	public function testStringAndIntReadTheGlobalConstants(): void
	{
		$conf = $GLOBALS['conf'];
		$c = new LegacyConfig(LegacyContext::fromGlobals());
		$conf->global->MVC_TEST_CONST = '42';
		try {
			self::assertSame('42', $c->string('MVC_TEST_CONST'));
			self::assertSame(42, $c->int('MVC_TEST_CONST'));
		} finally {
			unset($conf->global->MVC_TEST_CONST);
		}
		self::assertSame('dflt', $c->string('MVC_TEST_CONST', 'dflt'));
		self::assertSame(3, $c->int('MVC_TEST_CONST', 3));
	}

	public function testUrlRoots(): void
	{
		$c = new LegacyConfig(LegacyContext::fromGlobals());
		self::assertSame(DOL_URL_ROOT, $c->urlRoot());
		self::assertSame(DOL_MAIN_URL_ROOT, $c->mainUrlRoot());
	}

	public function testUserStringReadsTheCurrentUserConf(): void
	{
		$user = $GLOBALS['user'];
		$c = new LegacyConfig(LegacyContext::fromGlobals());
		$user->conf->MVC_TEST_USER_CONST = 'mine';
		try {
			self::assertSame('mine', $c->userString('MVC_TEST_USER_CONST'));
		} finally {
			unset($user->conf->MVC_TEST_USER_CONST);
		}
		self::assertSame('dflt', $c->userString('MVC_TEST_USER_CONST', 'dflt'));
	}
}
