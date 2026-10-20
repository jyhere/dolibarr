<?php

declare(strict_types=1);

namespace Dolibarr\Core\Container;

/**
 * Minimal service container: lazy singletons built by factories.
 *
 * Service ids are class or interface names.
 */
final class Container
{
	/** @var array<string,\Closure(Container):object> */
	private array $factories = [];

	/** @var array<string,object> */
	private array $instances = [];

	/** @var list<string> ids currently being built, in resolution order */
	private array $resolving = [];

	/**
	 * @param \Closure(Container):object $factory
	 */
	public function set(string $id, \Closure $factory): void
	{
		$this->factories[$id] = $factory;
		unset($this->instances[$id]);
	}

	public function instance(string $id, object $object): void
	{
		$this->instances[$id] = $object;
	}

	/**
	 * @throws ContainerException
	 */
	public function get(string $id): object
	{
		if (isset($this->instances[$id])) {
			return $this->instances[$id];
		}
		if (!isset($this->factories[$id])) {
			throw new ContainerException('Unknown service "' . $id . '"');
		}
		if (in_array($id, $this->resolving, true)) {
			throw new ContainerException(
				'Circular dependency: ' . implode(' -> ', array_merge($this->resolving, [$id]))
			);
		}

		$this->resolving[] = $id;
		try {
			$object = ($this->factories[$id])($this);
		} finally {
			array_pop($this->resolving);
		}

		return $this->instances[$id] = $object;
	}

	public function has(string $id): bool
	{
		return isset($this->instances[$id]) || isset($this->factories[$id]);
	}
}
