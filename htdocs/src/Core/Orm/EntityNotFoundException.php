<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * Repository::findOrFail(): no row with this id visible to the query (absent, or outside the entity scope).
 */
final class EntityNotFoundException extends \RuntimeException
{
	/**
	 * @param class-string $class
	 */
	public function __construct(public readonly string $class, public readonly int $id)
	{
		parent::__construct($class.' #'.$id.' not found');
	}
}
