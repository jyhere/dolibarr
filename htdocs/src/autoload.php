<?php

declare(strict_types=1);

namespace Dolibarr\Core;

/**
 * Minimal PSR-4 autoloader for the MVC core (Dolibarr\*) and the vendored Latte (Latte\*).
 */
final class Autoloader
{
	/** @var array<string,string> Namespace prefix => base directory (with trailing slash) */
	private static array $prefixes = [];

	/**
	 * @var array<string,string> Latte classes living in files that do not follow PSR-4 naming
	 *                           (Latte's composer.json uses a classmap for them)
	 */
	private static array $classMap = [];

	private static bool $registered = false;

	/**
	 * Register the autoloader (once) and add the given prefixes.
	 *
	 * @param array<string,string> $prefixes Extra namespace prefix => base directory
	 * @return void
	 */
	public static function register(array $prefixes = []): void
	{
		if (!self::$registered) {
			$latte = dirname(__DIR__).'/includes/latte/latte/src/Latte/';
			self::$prefixes = [
				'Dolibarr\\' => __DIR__.'/',
				'Latte\\' => $latte,
			];
			self::$classMap = [
				'Latte\\Feature' => $latte.'enums.php',
				'Latte\\TemplateFunction' => $latte.'attributes.php',
				'Latte\\TemplateFilter' => $latte.'attributes.php',
				'Latte\\Exception' => $latte.'exceptions.php',
				'Latte\\PositionAwareException' => $latte.'exceptions.php',
				'Latte\\CompileException' => $latte.'exceptions.php',
				'Latte\\RuntimeException' => $latte.'exceptions.php',
				'Latte\\TemplateNotFoundException' => $latte.'exceptions.php',
				'Latte\\SecurityViolationException' => $latte.'exceptions.php',
			];
			spl_autoload_register([self::class, 'load'], true, false);
			self::$registered = true;
		}

		foreach ($prefixes as $prefix => $dir) {
			self::$prefixes[$prefix] = rtrim($dir, '/').'/';
		}
	}

	/**
	 * Load a class file, silently doing nothing if it cannot be found.
	 *
	 * @param string $class Fully qualified class name
	 * @return void
	 */
	public static function load(string $class): void
	{
		if (isset(self::$classMap[$class])) {
			if (is_file(self::$classMap[$class])) {
				require_once self::$classMap[$class];
			}
			return;
		}
		foreach (self::$prefixes as $prefix => $dir) {
			if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
				continue;
			}
			$file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
			if (is_file($file)) {
				require_once $file;
				return;
			}
		}
	}
}

Autoloader::register();
