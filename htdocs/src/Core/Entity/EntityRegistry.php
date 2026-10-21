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

	/**
	 * @throws \LogicException When the slug is already registered (two modules would silently override each other)
	 */
	public function register(EntityDefinition $definition): void
	{
		if (isset($this->definitions[$definition->slug])) {
			throw new \LogicException('Entity slug '.$definition->slug.' is already registered');
		}
		$this->definitions[$definition->slug] = $definition;
	}

	/**
	 * @throws \InvalidArgumentException When the slug is unknown
	 */
	public function get(string $slug): EntityDefinition
	{
		return $this->definitions[$slug] ?? throw new \InvalidArgumentException('Unknown entity slug '.$slug);
	}

	/**
	 * The definition whose legacy element is $element (CommonObject::$element), null when none.
	 *
	 * @throws \LogicException When two definitions declare that element (which one is meant would be arbitrary)
	 */
	public function findByElement(string $element): ?EntityDefinition
	{
		$found = null;
		foreach ($this->definitions as $definition) {
			if ($definition->element === $element) {
				if ($found !== null) {
					throw new \LogicException('Entity slugs '.$found->slug.' and '.$definition->slug.' both declare the element '.$element);
				}
				$found = $definition;
			}
		}

		return $found;
	}

	public function has(string $slug): bool
	{
		return isset($this->definitions[$slug]);
	}
}
