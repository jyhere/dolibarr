<?php

declare(strict_types=1);

global $conf, $user, $langs, $db, $mysoc, $hookmanager, $extrafields;

define('NOSESSION', '1');
define('NOLOGIN', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');
define('NOREQUIREAJAX', '1');

require_once __DIR__.'/../../../htdocs/master.inc.php';
require_once __DIR__.'/../../../htdocs/src/autoload.php';

// Textarea heights main.inc.php defines (l.1415-1436, non firefox), read by DolEditor and the forms
for ($i = 1; $i <= 9; $i++) {
	if (!defined('ROWS_'.$i)) {
		define('ROWS_'.$i, $i);
	}
}

\Dolibarr\Core\Autoloader::register(['Dolibarr\\Tests\\' => __DIR__.'/']);

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}
