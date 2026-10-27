<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Cast;

/**
 * Dolibarr stores booleans as 0/1 integers: true when the integer value is > 0 (UserRepository::int() > 0).
 * Null stays null on a nullable property.
 */
final class BoolCast implements Cast
{
	public function __construct(private readonly bool $nullable)
	{
	}

	public function fromDatabase(mixed $value): ?bool
	{
		if ($value === null && $this->nullable) {
			return null;
		}
		if (is_bool($value)) {
			return $value;
		}

		return (is_numeric($value) ? (int) $value : 0) > 0;
	}

	public function toDatabase(mixed $value): ?int
	{
		$bool = $this->fromDatabase($value);

		return $bool === null ? null : (int) $bool;
	}
}
