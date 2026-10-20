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

	public function load(string $domain): void
	{
	}
}
