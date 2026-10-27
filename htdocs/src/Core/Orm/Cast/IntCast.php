<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Cast;

/**
 * UserRepository::int()/nint(): a non numeric value is 0, or null when the property is nullable.
 */
final class IntCast implements Cast
{
	public function __construct(private readonly bool $nullable)
	{
	}

	public function fromDatabase(mixed $value): ?int
	{
		if (is_numeric($value)) {
			return (int) $value;
		}

		return $this->nullable ? null : 0;
	}

	public function toDatabase(mixed $value): ?int
	{
		if (is_bool($value)) {
			return (int) $value;
		}

		return $this->fromDatabase($value);
	}
}
