<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Cast;

/**
 * UserRepository::str(): a scalar as a string, anything else null, or '' when the property is not nullable.
 */
final class StringCast implements Cast
{
	public function __construct(private readonly bool $nullable)
	{
	}

	public function fromDatabase(mixed $value): ?string
	{
		if (is_scalar($value)) {
			return (string) $value;
		}

		return $this->nullable ? null : '';
	}

	public function toDatabase(mixed $value): ?string
	{
		return $this->fromDatabase($value);
	}
}
