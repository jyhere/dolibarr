<?php

/**
 * MVC front controller. Runs in the GLOBAL scope so that main.inc.php exposes its globals.
 */

declare(strict_types=1);

if (!defined('CSRFCHECK_WITH_TOKEN')) {
	define('CSRFCHECK_WITH_TOKEN', '1');
}
require __DIR__.'/filefunc.inc.php';          // DOL_* constants and conf.php, no session
require __DIR__.'/src/autoload.php';

$kernel = new \Dolibarr\Core\Kernel(DOL_DOCUMENT_ROOT, DOL_DATA_ROOT, DOL_URL_ROOT, !empty($dolibarr_main_prod), DOL_VERSION);
try {
	$matchResult = $kernel->match();
} catch (\Throwable $e) {
	// Before main.inc.php: no dol_syslog(), no translation
	error_log('Dolibarr MVC: routing failed: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
	(new \Dolibarr\Core\Http\TextResponse('Internal Server Error', 500))->send();
	exit;
}
// A missing static file (image, css, js...) that no route claims: bare 404, without main.inc.php (no session, no database)
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if ($matchResult['matched'] === null && $matchResult['error'] instanceof \Dolibarr\Core\Routing\RouteNotFoundException
	&& \Dolibarr\Core\Kernel::isStaticAssetPath(is_string($requestUri) ? $requestUri : '')) {
	(new \Dolibarr\Core\Http\TextResponse('Not Found', 404))->send();
	exit;
}
$phpSelf = $kernel->phpSelfFor($matchResult);
if ($phpSelf !== null) {
	$_SERVER['PHP_SELF'] = $phpSelf;   // Before main.inc.php: legacy code (form actions, landing page guard, logs) reads it
}
foreach (\Dolibarr\Core\BootstrapMode::constantsFor($matchResult['mode']) as $const) {
	if (!defined($const)) {
		define($const, '1');
	}
}
if (!defined('DOL_MVC_FRONT')) {
	define('DOL_MVC_FRONT', '1');   // main.inc.php must not redirect the rewritten page to itself
}
$mainIncResult = require __DIR__.'/main.inc.php';   // main.inc.php does require_once 'filefunc.inc.php': already loaded, no effect
$kernel->run($matchResult, $mainIncResult)->send();
