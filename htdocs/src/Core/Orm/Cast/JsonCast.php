<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Cast;

/**
 * JSON column read as an associative array. An empty or invalid value is null, or [] when the property is not
 * nullable.
 */
final class JsonCast implements Cast
{
	private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

	public function __construct(private readonly bool $nullable)
	{
	}

	/**
	 * @return array<mixed>|null
	 */
	public function fromDatabase(mixed $value): ?array
	{
		if (is_array($value)) {
			return $value;
		}
		$decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;
		if (is_array($decoded)) {
			return $decoded;
		}

		return $this->nullable ? null : [];
	}

	/**
	 * @throws \JsonException When the value cannot be encoded
	 */
	public function toDatabase(mixed $value): ?string
	{
		if (is_string($value)) {
			$value = $this->fromDatabase($value);
		}
		if ($value === null) {
			return $this->nullable ? null : '[]';
		}

		return json_encode($value, self::FLAGS | JSON_THROW_ON_ERROR);
	}
}
