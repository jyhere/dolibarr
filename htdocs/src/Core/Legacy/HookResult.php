<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

final class HookResult
{
	/**
	 * @param array<mixed> $array
	 * @param list<string> $errors
	 */
	public function __construct(
		public readonly int $code,
		public readonly string $print,
		public readonly array $array,
		public readonly array $errors,
	) {
	}

	public function stopsStandardCode(): bool
	{
		return $this->code > 0;
	}

	public function failed(): bool
	{
		return $this->code < 0;
	}
}
