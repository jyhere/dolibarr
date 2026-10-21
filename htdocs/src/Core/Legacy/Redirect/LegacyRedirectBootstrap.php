<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy\Redirect;

use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Routing\RouteCache;
use Dolibarr\Core\Routing\RouteDefinitionsLoader;
use Dolibarr\Core\Routing\UrlGenerator;

/**
 * Entry point of the legacy redirects, called from main.inc.php (htdocs/src/legacy_redirect.inc.php).
 * A failure never breaks a page: any \Throwable is logged and the legacy page is served.
 */
final class LegacyRedirectBootstrap
{
	/**
	 * Redirect (302) the current legacy request to its rewritten route when one is declared, then exit.
	 * Returns when there is nothing to redirect.
	 *
	 * @param array<mixed>         $query   Legacy query parameters ($_GET)
	 * @param array<string,string> $headers Request headers
	 */
	public static function redirectIfNeeded(string $method, string $scriptName, array $query, array $headers, bool $enabled): void
	{
		if (!$enabled) {
			return;
		}
		$script = self::normalizeScript($scriptName, DOL_URL_ROOT);
		if ($script === null) {
			return;
		}
		$target = self::target($method, $script, $query, $headers, true);
		if ($target === null) {
			return;
		}
		if (self::emit($target)) {
			exit;
		}
	}

	/**
	 * Send the 302. False (nothing sent, the legacy page renders) when output has already started.
	 *
	 * @param (callable(): bool)|null $headersSent Replaces headers_sent(), for tests
	 */
	public static function emit(string $target, ?callable $headersSent = null): bool
	{
		if ($headersSent !== null ? $headersSent() : headers_sent()) {
			self::log('MVC legacy redirect: headers already sent, not redirecting to '.$target);

			return false;
		}
		header('Location: '.$target, true, 302);
		header('Cache-Control: no-store');

		return true;
	}

	/**
	 * The switch: the conf.php value ($dolibarr_main_mvc_legacy_redirects, null when unset) wins over the
	 * constant MAIN_MVC_LEGACY_REDIRECTS (default '1'); '0' disables.
	 */
	public static function isEnabled(?string $confValue, string $constValue): bool
	{
		return ($confValue ?? $constValue) !== '0';
	}

	/**
	 * Script path relative to htdocs ('user/card.php') from $_SERVER['SCRIPT_NAME'], or null when it is not a
	 * plain '.php' path under the URL root (traversal, backslash, double slash, NUL...).
	 */
	public static function normalizeScript(string $scriptName, string $urlRoot): ?string
	{
		$rest = $scriptName;
		if ($urlRoot !== '') {
			if ($rest !== $urlRoot && !str_starts_with($rest, $urlRoot.'/')) {
				return null;
			}
			$rest = substr($rest, strlen($urlRoot));
		}
		if (str_contains($rest, '..') || str_contains($rest, '\\') || str_contains($rest, '//') || str_contains($rest, "\0")) {
			return null;
		}
		$rest = ltrim($rest, '/');
		if ($rest === '' || !str_ends_with($rest, '.php')) {
			return null;
		}

		return $rest;
	}

	/**
	 * Request headers rebuilt the way Request::fromGlobals does.
	 *
	 * @param array<mixed> $server $_SERVER
	 * @return array<string,string>
	 */
	public static function headersFromServer(array $server): array
	{
		$headers = [];
		foreach ($server as $key => $value) {
			if (!is_string($value) || !str_starts_with((string) $key, 'HTTP_')) {
				continue;
			}
			$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))))] = $value;
		}

		return $headers;
	}

	/**
	 * Target URL of a legacy request, or null (legacy page served). Never throws.
	 * Null as well until the front controller has created its marker (FrontControllerMarker), checked last.
	 *
	 * @param array<mixed>               $query    Legacy query parameters
	 * @param array<string,string>       $headers  Request headers
	 * @param list<ModuleInterface>|null $modules  Modules, src/modules.php when null (tests inject a list)
	 * @param string|null                $dataRoot Cache root, DOL_DATA_ROOT when null
	 * @param bool|null                  $prod     Production mode (no freshness check), from conf.php when null
	 * @param (\Closure(): list<\Dolibarr\Core\Routing\RouteDefinition>)|null $definitions Route definitions provider, the cached loader when null (tests spy on it)
	 */
	public static function target(
		string $method,
		string $script,
		array $query,
		array $headers,
		bool $enabled,
		?array $modules = null,
		?string $dataRoot = null,
		?bool $prod = null,
		?\Closure $definitions = null
	): ?string {
		if (!$enabled) {
			return null;
		}

		try {
			$dataRoot ??= DOL_DATA_ROOT;
			$prod ??= !empty($GLOBALS['dolibarr_main_prod']);
			$modulesFile = null;
			if ($modules === null) {
				$modulesFile = DOL_DOCUMENT_ROOT.'/src/modules.php';
				/** @var list<ModuleInterface> $modules */
				$modules = require $modulesFile;
			}

			// Hot path: stamp from the module class names only, then the cached rules of this script only.
			$loader = new RouteDefinitionsLoader($dataRoot, $prod, (string) \constant('DOL_VERSION'), $modules, $modulesFile);
			$definitions ??= static fn (): array => $loader->load();
			$stamp = $loader->stamp();
			$cache = new RedirectCache($dataRoot.'/cache/legacy_redirects.php');

			$rows = self::cachedRows($cache, $stamp, $modules, $prod, $modulesFile);
			if ($rows !== null) {
				$rows = array_values(array_filter($rows, static fn (array $row): bool => $row['script'] === $script));
				if ($rows === []) {
					return null;
				}
				$routes = $definitions();
				$table = LegacyRedirectTable::fromArray($rows);
			} else {
				$routes = $definitions();
				$table = LegacyRedirectTable::fromModules($modules, $routes);
				try {
					$cache->store($table->toArray(), $stamp);
				} catch (\RuntimeException $e) {
					self::log('MVC legacy redirect: table cache not written: '.$e->getMessage());
				}
			}

			$target = (new LegacyRedirector($table, new UrlGenerator($routes, DOL_URL_ROOT)))->resolve($method, $script, $query, $headers);

			// Only once app.php has served a route: without the front-controller rewrite the target would be a 404
			return $target !== null && (new FrontControllerMarker($dataRoot))->exists() ? $target : null;
		} catch (\Throwable $e) {
			self::log('MVC legacy redirect: '.get_class($e).': '.$e->getMessage());

			return null;
		}
	}

	/**
	 * Rows of the redirect cache, or null on a miss (absent, stale, other stamp): the table must then be rebuilt.
	 *
	 * @param list<ModuleInterface> $modules
	 * @return list<array<string,mixed>>|null
	 */
	private static function cachedRows(RedirectCache $cache, string $stamp, array $modules, bool $prod, ?string $modulesFile): ?array
	{
		if (!$prod) {
			$files = $modulesFile !== null ? [$modulesFile] : [];
			foreach ($modules as $module) {
				$moduleFile = (new \ReflectionClass($module))->getFileName();
				if ($moduleFile !== false) {
					$files[] = $moduleFile;
				}
			}
			if (!(new RouteCache($cache->file()))->isFresh($files)) {
				return null;
			}
		}

		return $cache->load($stamp);
	}

	private static function log(string $message): void
	{
		if (function_exists('dol_syslog')) {
			dol_syslog($message, LOG_WARNING);
		} else {
			error_log($message);
		}
	}
}
