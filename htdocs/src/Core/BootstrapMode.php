<?php

declare(strict_types=1);

namespace Dolibarr\Core;

/**
 * How main.inc.php must be included for a request.
 */
final class BootstrapMode
{
	public const PUBLIC = 'public';
	public const PROTECTED = 'protected';

	/**
	 * @return list<string> Constants to define before including main.inc.php
	 * @throws \InvalidArgumentException On unknown mode
	 */
	public static function constantsFor(string $mode): array
	{
		return match ($mode) {
			self::PUBLIC => ['NOLOGIN', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX'],
			self::PROTECTED => ['NOREDIRECTBYMAINTOLOGIN'],
			default => throw new \InvalidArgumentException('Unknown bootstrap mode "'.$mode.'"'),
		};
	}
}
