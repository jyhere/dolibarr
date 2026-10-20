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
		$h = new LegacyHooks($GLOBALS['hookmanager']);
		$h->init(['usercard']);
		$o = null;
		$a = 'view';
		$r = $h->execute('formObjectOptions', ['id' => 1], $o, $a);
		self::assertSame(0, $r->code);
		self::assertSame('', $r->print);
		self::assertSame('', $r->output);
	}

	public function testEchoedOutputIsCapturedApartFromResPrint(): void
	{
		$hm = new \HookManager($GLOBALS['db']);
		ScriptedHooks::register($hm, 'usercard', new ScriptedHooks(['formObjectOptions' => ['echo' => '<i>echoed</i>', 'print' => '<b>printed</b>', 'code' => 0]]));
		$o = null;
		$a = '';

		ob_start();
		$r = (new LegacyHooks($hm))->execute('formObjectOptions', [], $o, $a);
		$leaked = (string) ob_get_clean();

		self::assertSame('', $leaked);
		self::assertSame('<i>echoed</i>', $r->output);
		self::assertSame('<b>printed</b>', $r->print);
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
		$o = null;
		$a = '';
		$level = ob_get_level();
		try {
			(new LegacyHooks($hm))->execute('formObjectOptions', [], $o, $a);
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
		$o = null;
		$a = '';

		$r = (new LegacyHooks($hm))->execute('doActions', [], $o, $a);

		self::assertTrue($r->failed());
		self::assertSame(['E0', 'E1', 'E2'], $r->errors);
	}
}
