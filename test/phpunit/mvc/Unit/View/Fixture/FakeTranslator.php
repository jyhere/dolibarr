<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\View\Fixture;

use Dolibarr\Core\Translator;

final class FakeTranslator implements Translator
{
	public function trans(string $key, string ...$args): string
	{
		return 'TR:'.$key;
	}

	public function transnoentities(string $key, string ...$args): string
	{
		return 'TN:'.$key;
	}

	/**
	 * 'TH:<key>', then ':' and the arguments escaped, joined by '|', when there are some.
	 */
	public function transHtml(string $key, string ...$args): string
	{
		return 'TH:'.$key.($args === [] ? '' : ':'.implode('|', array_map(static fn (string $argument): string => htmlspecialchars($argument, ENT_COMPAT, 'UTF-8'), $args)));
	}

	public function load(string $domain): void
	{
	}
}
