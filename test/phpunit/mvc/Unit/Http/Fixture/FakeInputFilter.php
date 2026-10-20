<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Http\Fixture;

use Dolibarr\Core\Http\InputFilter;

/**
 * Input filter answering from a fixed map, recording the last filter() call.
 */
final class FakeInputFilter implements InputFilter
{
	/** @var array{0: string, 1: string}|null */
	public ?array $lastCall = null;

	/** GETPOST() method of the last filter() call */
	public ?int $lastMethod = null;

	/**
	 * @param array<string,mixed> $values Filtered values by key
	 */
	public function __construct(private readonly array $values)
	{
	}

	public function filter(string $key, string $filter, mixed $default = null, int $method = 0): mixed
	{
		$this->lastCall = [$key, $filter];
		$this->lastMethod = $method;

		return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
	}

	public function isSet(string $key): bool
	{
		return array_key_exists($key, $this->values);
	}
}
