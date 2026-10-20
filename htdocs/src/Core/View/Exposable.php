<?php

declare(strict_types=1);

namespace Dolibarr\Core\View;

/**
 * Object that can be exposed in JSON responses.
 */
interface Exposable
{
	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array;
}
