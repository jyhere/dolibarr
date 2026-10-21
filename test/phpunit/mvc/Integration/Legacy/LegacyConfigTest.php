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
		$config = new LegacyConfig($ctx);
		self::assertSame($GLOBALS['conf']->entity, $config->entity());
		self::assertTrue($config->isModuleEnabled('user'));
		self::assertFalse($config->isModuleEnabled('notamodule'));
	}

	public function testStringAndIntReadTheGlobalConstants(): void
	{
		$conf = $GLOBALS['conf'];
		$config = new LegacyConfig(LegacyContext::fromGlobals());
		$conf->global->MVC_TEST_CONST = '42';
		try {
			self::assertSame('42', $config->string('MVC_TEST_CONST'));
			self::assertSame(42, $config->int('MVC_TEST_CONST'));
		} finally {
			unset($conf->global->MVC_TEST_CONST);
		}
		self::assertSame('dflt', $config->string('MVC_TEST_CONST', 'dflt'));
		self::assertSame(3, $config->int('MVC_TEST_CONST', 3));
	}

	public function testUrlRoots(): void
	{
		$config = new LegacyConfig(LegacyContext::fromGlobals());
		self::assertSame(DOL_URL_ROOT, $config->urlRoot());
		self::assertSame(DOL_MAIN_URL_ROOT, $config->mainUrlRoot());
	}

	public function testUserStringReadsTheCurrentUserConf(): void
	{
		$user = $GLOBALS['user'];
		$config = new LegacyConfig(LegacyContext::fromGlobals());
		$user->conf->MVC_TEST_USER_CONST = 'mine';
		try {
			self::assertSame('mine', $config->userString('MVC_TEST_USER_CONST'));
		} finally {
			unset($user->conf->MVC_TEST_USER_CONST);
		}
		self::assertSame('dflt', $config->userString('MVC_TEST_USER_CONST', 'dflt'));
	}
}
