<?php

declare(strict_types=1);

namespace Dolibarr\Core;

/**
 * Logger bridge to dol_syslog().
 */
final class Logger
{
	public function debug(string $m): void
	{
		dol_syslog($m, LOG_DEBUG);
	}

	public function info(string $m): void
	{
		dol_syslog($m, LOG_INFO);
	}

	public function warning(string $m): void
	{
		dol_syslog($m, LOG_WARNING);
	}

	public function error(string $m): void
	{
		dol_syslog($m, LOG_ERR);
	}
}
