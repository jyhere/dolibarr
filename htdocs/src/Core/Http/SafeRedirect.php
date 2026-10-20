<?php

declare(strict_types=1);

namespace Dolibarr\Core\Http;

/**
 * Validation of redirect targets coming from the client (backtopage, urlfrom...).
 */
final class SafeRedirect
{
	/**
	 * $candidate when it is a path of this instance: relative, not protocol relative, no
	 * backslash or control character, under $urlRoot when it is not empty; null otherwise.
	 */
	public static function relativePath(?string $candidate, string $urlRoot): ?string
	{
		if ($candidate === null || $candidate === '' || $candidate[0] !== '/' || str_starts_with($candidate, '//')) {
			return null;
		}
		if (str_contains($candidate, '\\') || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
			return null;
		}
		// Under $urlRoot: '/dolibarr/x' for '/dolibarr', never '/dolibarrother/x'
		if ($urlRoot !== '' && (!str_starts_with($candidate, $urlRoot) || !in_array(substr($candidate, strlen($urlRoot), 1), ['', '/', '?', '#'], true))) {
			return null;
		}

		return $candidate;
	}
}
