<?php

declare(strict_types=1);

namespace Dolibarr\Core\View;

/**
 * Immutable description of a view: template name, data, HTTP status and exposed data.
 *
 * $data is for the template only. $exposed is what a JSON client receives (and the
 * template too): it must hold only Exposable objects, scalars, null and arrays of them.
 */
final class ViewModel
{
	/**
	 * @param array<string, mixed> $data    Template data, never serialised to JSON
	 * @param array<string, mixed> $exposed Data given to the template and serialised to JSON
	 */
	public function __construct(
		public readonly string $template,
		public readonly array $data = [],
		public readonly int $status = 200,
		public readonly array $exposed = []
	) {
	}

	public function with(string $key, mixed $value): self
	{
		return new self($this->template, [...$this->data, $key => $value], $this->status, $this->exposed);
	}

	public function expose(string $key, mixed $value): self
	{
		return new self($this->template, $this->data, $this->status, [...$this->exposed, $key => $value]);
	}

	/**
	 * Variables of the template: the data and the exposed data.
	 *
	 * @return array<string, mixed>
	 */
	public function templateData(): array
	{
		return [...$this->data, ...$this->exposed];
	}
}
