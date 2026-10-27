<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * The parameters of one compilation, named as they are bound: :p1, :p2… in order, or a chosen name
 * (:entities) reused while its value is the same.
 */
final class QueryParameters
{
	/** @var array<string, mixed> */
	private array $params = [];

	private int $counter = 0;

	/**
	 * @return string The placeholder (':p1')
	 */
	public function add(mixed $value): string
	{
		$name = 'p'.(++$this->counter);
		$this->params[$name] = $value;

		return ':'.$name;
	}

	/**
	 * @return string ':name', or ':name2'… when the name is already bound to another value
	 */
	public function named(string $name, mixed $value): string
	{
		$candidate = $name;
		$suffix = 1;
		while (array_key_exists($candidate, $this->params) && $this->params[$candidate] !== $value) {
			$candidate = $name.(++$suffix);
		}
		$this->params[$candidate] = $value;

		return ':'.$candidate;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array
	{
		return $this->params;
	}
}
