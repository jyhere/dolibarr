<?php

declare(strict_types=1);

namespace Dolibarr\Core\Entity;

/**
 * The entity types declared by the modules, by slug.
 */
final class EntityRegistry
{
	/** @var array<string, EntityDefinition> */
	private array $definitions = [];

	public function register(EntityDefinition $d): void
	{
		$this->definitions[$d->slug] = $d;
	}

	/**
	 * @throws \InvalidArgumentException When the slug is unknown
	 */
	public function get(string $slug): EntityDefinition
	{
		return $this->definitions[$slug] ?? throw new \InvalidArgumentException('Unknown entity slug '.$slug);
	}

	public function has(string $slug): bool
	{
		return isset($this->definitions[$slug]);
	}
}
