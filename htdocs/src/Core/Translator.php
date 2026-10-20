<?php

declare(strict_types=1);

namespace Dolibarr\Core;

interface Translator
{
	public function trans(string $key, string ...$args): string;

	public function transnoentities(string $key, string ...$args): string;

	public function load(string $domain): void;
}
