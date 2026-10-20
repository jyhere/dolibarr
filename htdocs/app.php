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

$kernel = new \Dolibarr\Core\Kernel(DOL_DOCUMENT_ROOT, DOL_DATA_ROOT, DOL_URL_ROOT, !empty($dolibarr_main_prod));
$matchResult = $kernel->match();
$phpSelf = $kernel->phpSelfFor($matchResult);
if ($phpSelf !== null) {
	$_SERVER['PHP_SELF'] = $phpSelf;   // Before main.inc.php: legacy code (form actions, landing page guard, logs) reads it
}
foreach (\Dolibarr\Core\BootstrapMode::constantsFor($matchResult['mode']) as $const) {
	if (!defined($const)) {
		define($const, '1');
	}
}
$mainIncResult = require __DIR__.'/main.inc.php';   // main.inc.php does require_once 'filefunc.inc.php': already loaded, no effect
$kernel->run($matchResult, $mainIncResult)->send();
