<?php

declare(strict_types=1);

namespace Dolibarr\Core;

interface Translator
{
	public function trans(string $key, string ...$args): string;

	public function transnoentities(string $key, string ...$args): string;

	/**
	 * trans() for an HTML context (a flash message, a legacy dialog): the arguments, user data, are escaped;
	 * the tags of the translation itself are kept, as the legacy \Translate::trans() keeps them.
	 */
	public function transHtml(string $key, string ...$args): string;

	public function load(string $domain): void;
}
