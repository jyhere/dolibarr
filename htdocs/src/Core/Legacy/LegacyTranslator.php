<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\Translator;

/**
 * Translator backed by \Translate. trans() returns PLAIN TEXT (no HTML entities,
 * via transnoentities) so that Latte auto-escaping does not double-encode.
 */
final class LegacyTranslator implements Translator
{
	public function __construct(private readonly \Translate $langs)
	{
	}

	public function trans(string $key, string ...$args): string
	{
		return $this->langs->transnoentities($key, ...$this->pad($args));
	}

	public function transnoentities(string $key, string ...$args): string
	{
		return $this->langs->transnoentitiesnoconv($key, ...$this->pad($args));
	}

	public function transHtml(string $key, string ...$args): string
	{
		return $this->trans($key, ...array_map(static fn (string $a): string => htmlspecialchars($a, ENT_COMPAT, 'UTF-8'), $args));
	}

	public function load(string $domain): void
	{
		$this->langs->load($domain);
	}

	/**
	 * Dolibarr accepts at most 5 positional parameters.
	 *
	 * @param array<int,string> $args
	 * @return array{string,string,string,string,string}
	 */
	private function pad(array $args): array
	{
		$args = array_values($args);

		return [$args[0] ?? '', $args[1] ?? '', $args[2] ?? '', $args[3] ?? '', $args[4] ?? ''];
	}
}
