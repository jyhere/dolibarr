<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Naming convention of the MVC rewrite (test/mvc/README.md): no single-letter variable,
 * except $i/$j loop counters and $e for a caught exception.
 */
final class ReadableNamesTest extends TestCase
{
	private const ALLOWED = ['i', 'j', 'e'];

	public function testNoSingleLetterVariableInCodeTemplatesAndTests(): void
	{
		$root = dirname(__DIR__, 4);
		$offences = [];
		foreach (['htdocs/src', 'test/phpunit/mvc'] as $directory) {
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS));
			foreach ($files as $file) {
				if (!in_array($file->getExtension(), ['php', 'latte'], true)) {
					continue;
				}
				$lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
				foreach ($lines as $index => $line) {
					if (!preg_match_all('/\$([a-zA-Z])(?![a-zA-Z0-9_])/', $line, $matches)) {
						continue;
					}
					$names = array_diff(array_unique($matches[1]), self::ALLOWED);
					if ($names !== []) {
						$offences[] = substr($file->getPathname(), strlen($root) + 1).':'.($index + 1).' $'.implode(', $', $names);
					}
				}
			}
		}
		sort($offences);

		self::assertSame([], $offences, "Use descriptive variable names (see test/mvc/README.md, Naming convention).");
	}
}
