<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Tests\Integration\Fixture\ScriptedHooks;
use PHPUnit\Framework\TestCase;

final class LegacyHooksTest extends TestCase
{
	public function testHooksWithoutModulesReturnCodeZero(): void
	{
		$hooks = new LegacyHooks($GLOBALS['hookmanager']);
		$hooks->init(['usercard']);
		$object = null;
		$action = 'view';
		$result = $hooks->execute('formObjectOptions', ['id' => 1], $object, $action);
		self::assertSame(0, $result->code);
		self::assertSame('', $result->print);
		self::assertSame('', $result->output);
	}

	public function testEchoedOutputIsCapturedApartFromResPrint(): void
	{
		$hm = new \HookManager($GLOBALS['db']);
		ScriptedHooks::register($hm, 'usercard', new ScriptedHooks(['formObjectOptions' => ['echo' => '<i>echoed</i>', 'print' => '<b>printed</b>', 'code' => 0]]));
		$object = null;
		$action = '';

		ob_start();
		$result = (new LegacyHooks($hm))->execute('formObjectOptions', [], $object, $action);
		$leaked = (string) ob_get_clean();

		self::assertSame('', $leaked);
		self::assertSame('<i>echoed</i>', $result->output);
		self::assertSame('<b>printed</b>', $result->print);
	}

	public function testOutputBufferIsClosedWhenTheHookThrows(): void
	{
		$hm = new \HookManager($GLOBALS['db']);
		ScriptedHooks::register($hm, 'usercard', new class () {
			/** @var array<mixed> */
			public array $results = [];

			public ?string $resprints = null;

			public string $error = '';

			/** @var list<string> */
			public array $errors = [];

			/** @var list<string> */
			public array $warnings = [];

			public function formObjectOptions(): int
			{
				echo 'partial';
				throw new \RuntimeException('boom');
			}
		});
		$object = null;
		$action = '';
		$level = ob_get_level();
		try {
			(new LegacyHooks($hm))->execute('formObjectOptions', [], $object, $action);
			self::fail('The exception must go through');
		} catch (\RuntimeException $e) {
			self::assertSame('boom', $e->getMessage());
		}
		self::assertSame($level, ob_get_level());
	}

	public function testErrorsInTheOrderOfSetEventMessages(): void
	{
		$hm = new \HookManager($GLOBALS['db']);
		ScriptedHooks::register($hm, 'usercard', new ScriptedHooks(['doActions' => ['code' => -1, 'error' => 'E0', 'errors' => ['E1', 'E2']]]));
		$object = null;
		$action = '';

		$result = (new LegacyHooks($hm))->execute('doActions', [], $object, $action);

		self::assertTrue($result->failed());
		self::assertSame(['E0', 'E1', 'E2'], $result->errors);
	}
}
