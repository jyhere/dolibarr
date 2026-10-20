<?php

declare(strict_types=1);

/**
 * Registry of the MVC modules.
 *
 * @return list<\Dolibarr\Core\Module\ModuleInterface>
 */
return [
	new \Dolibarr\Module\User\UserModule(),
	new \Dolibarr\Module\Notes\NotesModule(),
];
