<?php

declare(strict_types=1);

namespace Dolibarr\Core\View;

/**
 * Immutable description of a view: template name, data and HTTP status.
 */
final class ViewModel
{
	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct(
		public readonly string $template,
		public readonly array $data = [],
		public readonly int $status = 200
	) {
	}

	public function with(string $key, mixed $value): self
	{
		return new self($this->template, [...$this->data, $key => $value], $this->status);
	}
}
