<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm\Fixture;

use Dolibarr\Core\Orm\EntityScope;

/**
 * Entities given for any element, and the elements asked for.
 */
final class FakeEntityScope implements EntityScope
{
	/** @var list<string> */
	public array $askedElements = [];

	/**
	 * @param list<int> $entities
	 */
	public function __construct(private readonly array $entities = [1, 2])
	{
	}

	public function entitiesFor(string $element): array
	{
		$this->askedElements[] = $element;

		return $this->entities;
	}
}
