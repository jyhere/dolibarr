<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * "Now" as the legacy stores it: $db->idate(dol_now()) writes the server wall clock without zone. The value is
 * labelled UTC, as the dates read back from the database (spec §5.6), so it binds and compares without conversion.
 */
final class DatabaseClock
{
	public static function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable(date('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
	}
}
