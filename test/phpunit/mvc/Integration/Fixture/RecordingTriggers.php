<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Fixture;

/**
 * Records the ECMFILES_* and LINK_* triggers the real legacy run_triggers() dispatches, whoever calls it (the legacy
 * \EcmFiles or \Link create/update/delete of a parity test, or LegacyTriggers::run of the new code).
 *
 * install() registers the directory Triggers/ in $conf->modules_parts['triggers']: run_triggers() scans it like
 * the trigger directory of a module and loads interface_99_all_MvcRecordingTriggers.class.php, whose
 * runTrigger() calls record(). A mock of LegacyTriggers would only see the new code; this one sees both.
 */
final class RecordingTriggers
{
	private const DIRECTORY = '/../test/phpunit/mvc/Integration/Fixture/Triggers';

	/** @var list<array<string, mixed>> */
	private static array $calls = [];

	/** @var array<string, string> Trigger action => error message to return it with a failure (-1) */
	private static array $failures = [];

	public static function install(): void
	{
		self::reset();
		$conf = $GLOBALS['conf'];
		self::assertConf($conf);
		if (!in_array(self::DIRECTORY, $conf->modules_parts['triggers'], true)) {
			$conf->modules_parts['triggers'][] = self::DIRECTORY;
		}
	}

	public static function uninstall(): void
	{
		$conf = $GLOBALS['conf'];
		self::assertConf($conf);
		$conf->modules_parts['triggers'] = array_values(array_filter(
			$conf->modules_parts['triggers'],
			static fn (string $directory): bool => $directory !== self::DIRECTORY
		));
		self::reset();
	}

	public static function reset(): void
	{
		self::$calls = [];
		self::$failures = [];
	}

	public static function failOn(string $action, string $message): void
	{
		self::$failures[$action] = $message;
	}

	/**
	 * @param array<string, mixed> $call
	 */
	public static function record(array $call): void
	{
		self::$calls[] = $call;
	}

	public static function failureOf(string $action): ?string
	{
		return self::$failures[$action] ?? null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function calls(): array
	{
		return self::$calls;
	}

	/**
	 * @return list<array<string, mixed>> The calls since the last forget(), which empties the list
	 */
	public static function forget(): array
	{
		$calls = self::$calls;
		self::$calls = [];

		return $calls;
	}

	/**
	 * @phpstan-assert \Conf $conf
	 */
	private static function assertConf(mixed $conf): void
	{
		if (!$conf instanceof \Conf) {
			throw new \LogicException('The legacy $conf is not initialized');
		}
	}
}
