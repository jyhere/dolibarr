<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

/**
 * Why an upload, rename or link is refused: a translation key of the "errors" domain and its parameters.
 * The caller loads the domain and translates.
 */
final class Refusal
{
	/**
	 * @param array<int, string> $params
	 */
	public function __construct(
		public readonly string $key,
		public readonly array $params = [],
	) {
	}
}
