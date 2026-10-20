<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

final class HookResult
{
	/**
	 * @param string       $print  $hookmanager->resPrint, what the hooks returned to print
	 * @param array<mixed> $array  $hookmanager->resArray
	 * @param list<string> $errors $hookmanager->error then ->errors, as setEventMessages() lists them
	 * @param string       $output What the hooks echoed directly while they ran (a legacy page prints it at the call point)
	 */
	public function __construct(
		public readonly int $code,
		public readonly string $print,
		public readonly array $array,
		public readonly array $errors,
		public readonly string $output = '',
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
