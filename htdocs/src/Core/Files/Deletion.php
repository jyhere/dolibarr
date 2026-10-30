<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

/**
 * How FileStorage::delete() ended. A deleteFile hook that takes the deletion over (code > 0) is a success for the
 * caller but dol_delete_file() then returns at once (files.lib.php l.1693-1698): no index row is removed and no
 * trigger is sent.
 */
enum Deletion
{
	case Deleted;
	case TakenOverByHook;
	case Failed;

	public function succeeded(): bool
	{
		return $this !== self::Failed;
	}
}
