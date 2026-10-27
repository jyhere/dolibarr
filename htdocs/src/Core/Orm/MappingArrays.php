<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * Typed reads of the arrays a mapping is cached as: a malformed cache fails loudly instead of building a
 * half-valid mapping (MappingCache then ignores it).
 *
 * @internal
 */
final class MappingArrays
{
	/**
	 * @param array<mixed> $data
	 * @throws \UnexpectedValueException
	 */
	public static function string(array $data, string $key): string
	{
		$value = $data[$key] ?? null;
		if (!is_string($value) || $value === '') {
			throw new \UnexpectedValueException('Mapping array: '.$key.' must be a non-empty string');
		}

		return $value;
	}

	/**
	 * @param array<mixed> $data
	 * @throws \UnexpectedValueException
	 */
	public static function nullableString(array $data, string $key): ?string
	{
		return ($data[$key] ?? null) === null ? null : self::string($data, $key);
	}

	/**
	 * @param array<mixed> $data
	 * @throws \UnexpectedValueException
	 */
	public static function bool(array $data, string $key): bool
	{
		$value = $data[$key] ?? null;
		if (!is_bool($value)) {
			throw new \UnexpectedValueException('Mapping array: '.$key.' must be a boolean');
		}

		return $value;
	}

	/**
	 * @param array<mixed> $data
	 * @return array<mixed>
	 * @throws \UnexpectedValueException
	 */
	public static function list(array $data, string $key): array
	{
		$value = $data[$key] ?? null;
		if (!is_array($value)) {
			throw new \UnexpectedValueException('Mapping array: '.$key.' must be an array');
		}

		return $value;
	}
}
