<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\View\Fixture;

use Dolibarr\Core\View\Exposable;

final class ExposableStub implements Exposable
{
	/**
	 * @param array<string,mixed> $data
	 */
	public function __construct(private readonly array $data)
	{
	}

	public function toArray(): array
	{
		return $this->data;
	}
}
