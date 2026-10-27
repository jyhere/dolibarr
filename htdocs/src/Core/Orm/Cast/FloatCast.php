<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Cast;

/**
 * UserRepository::float(): a non numeric value is null, or 0.0 when the property is not nullable.
 */
final class FloatCast implements Cast
{
	public function __construct(private readonly bool $nullable)
	{
	}

	public function fromDatabase(mixed $value): ?float
	{
		if (is_numeric($value)) {
			return (float) $value;
		}

		return $this->nullable ? null : 0.0;
	}

	public function toDatabase(mixed $value): ?float
	{
		return $this->fromDatabase($value);
	}
}
