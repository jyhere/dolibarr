<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

/**
 * Flash messages bridge to setEventMessages() ($_SESSION['dol_events']).
 */
final class FlashBag
{
	public function success(string $msg): void
	{
		setEventMessages(null, [$msg], 'mesgs');
	}

	public function warning(string $msg): void
	{
		setEventMessages(null, [$msg], 'warnings');
	}

	public function error(string $msg): void
	{
		setEventMessages(null, [$msg], 'errors');
	}
}
