<?php

declare(strict_types=1);

namespace Dolibarr\Core;

/**
 * Logger bridge to dol_syslog().
 */
final class Logger
{
	public function debug(string $message): void
	{
		dol_syslog($message, LOG_DEBUG);
	}

	public function info(string $message): void
	{
		dol_syslog($message, LOG_INFO);
	}

	public function warning(string $message): void
	{
		dol_syslog($message, LOG_WARNING);
	}

	public function error(string $message): void
	{
		dol_syslog($message, LOG_ERR);
	}
}
