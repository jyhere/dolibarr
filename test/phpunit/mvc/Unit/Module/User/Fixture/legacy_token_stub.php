<?php

declare(strict_types=1);

/*
 * Stand-in for the Dolibarr newToken() in unit tests (Dolibarr is not loaded there): the token() template function.
 */
if (!function_exists('newToken')) {
	function newToken(): string
	{
		return 'stub-token';
	}
}
