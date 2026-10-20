<?php

declare(strict_types=1);

/*
 * Stand-in for the Dolibarr dol_print_date() in unit tests (Dolibarr is not loaded there):
 * echoes its arguments so the date filter can be checked.
 */
if (!function_exists('dol_print_date')) {
	function dol_print_date(mixed $time, string $format = '', string $tzoutput = 'auto'): string
	{
		return 'DATE('.(is_scalar($time) ? (string) $time : gettype($time)).','.$format.','.$tzoutput.')';
	}
}
