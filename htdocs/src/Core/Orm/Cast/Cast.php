<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Cast;

/**
 * Conversion between a column value and a property value. fromDatabase() accepts both shapes the drivers give
 * (mysqli native numbers, pgsql and fallback strings) with the same result; toDatabase() gives what is bound.
 */
interface Cast
{
	public function fromDatabase(mixed $value): mixed;

	public function toDatabase(mixed $value): int|float|string|null;
}
