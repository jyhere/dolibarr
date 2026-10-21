<?php

/**
 * Included by main.inc.php (global scope, conf loaded, no user yet): redirect a legacy page to its rewritten
 * route when a module declares one. Switch: $dolibarr_main_mvc_legacy_redirects (conf.php) then the constant
 * MAIN_MVC_LEGACY_REDIRECTS; '0' disables. Any failure leaves the legacy page to run.
 */

declare(strict_types=1);

try {
	if (!class_exists(\Dolibarr\Core\Legacy\Redirect\LegacyRedirectBootstrap::class)) {
		require_once __DIR__.'/autoload.php';
	}
	$mvcRedirectEnabled = \Dolibarr\Core\Legacy\Redirect\LegacyRedirectBootstrap::isEnabled(
		isset($dolibarr_main_mvc_legacy_redirects) && is_scalar($dolibarr_main_mvc_legacy_redirects) ? (string) $dolibarr_main_mvc_legacy_redirects : null,
		getDolGlobalString('MAIN_MVC_LEGACY_REDIRECTS', '1')
	);
	if ($mvcRedirectEnabled) {
		$mvcRedirectScript = $_SERVER['SCRIPT_NAME'] ?? '';
		$mvcRedirectMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		\Dolibarr\Core\Legacy\Redirect\LegacyRedirectBootstrap::redirectIfNeeded(
			is_string($mvcRedirectMethod) ? $mvcRedirectMethod : 'GET',
			is_string($mvcRedirectScript) ? $mvcRedirectScript : '',
			$_GET,
			\Dolibarr\Core\Legacy\Redirect\LegacyRedirectBootstrap::headersFromServer($_SERVER),
			true
		);
	}
} catch (\Throwable $mvcRedirectError) {
	dol_syslog('MVC legacy redirect: '.get_class($mvcRedirectError).': '.$mvcRedirectError->getMessage(), LOG_WARNING);
}
unset($mvcRedirectEnabled, $mvcRedirectScript, $mvcRedirectMethod, $mvcRedirectError);
