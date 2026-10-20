<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

/**
 * Legacy input filters applied to values that GETPOST() cannot read itself (a Request
 * built by hand, a value stored in the session).
 */
final class LegacyInput
{
	/**
	 * GETPOST($name) for a 'backto*' or 'urlfrom*' parameter: sanitizeVal($value, 'alphanohtml'),
	 * then the URL cleaning GETPOST() applies to these parameter names only
	 * (core/lib/functions.lib.php, "Sanitizing for special parameters"). It removes, among
	 * others, a leading 'scheme://' or '//'. sanitizeVal() alone keeps '//evil.example/x'.
	 */
	public static function urlParameter(string $value): string
	{
		$out = sanitizeVal($value, 'alphanohtml');
		$out = is_string($out) ? $out : '';
		$out = str_replace('\\', '/', $out);
		$out = str_replace([':', ';', '@', "\t", ' '], '', $out);
		do {
			$previous = $out;
			$out = str_ireplace(['javascript', 'vbscript', '&colon', '&#'], '', $out);
			$out = (string) preg_replace(['/^[^\?]*%/'], '', $out);
			$out = (string) preg_replace(['/^[a-z]*\/\s*\/+/i'], '', $out);
		} while ($previous !== $out);

		return $out;
	}
}
