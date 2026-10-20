<?php

declare(strict_types=1);

namespace Dolibarr\Core\Entity;

/**
 * Describes an entity type a module exposes to the generic parts of the core (tabs, notes, documents...).
 */
final class EntityDefinition
{
	/**
	 * @param string       $slug                URL slug of the entity type (e.g. 'user')
	 * @param class-string $entityClass         Entity class
	 * @param string       $repositoryId        Container id of its repository
	 * @param string       $element             Legacy element code (e.g. 'user')
	 * @param string       $tableElement        Legacy table element (e.g. 'user')
	 * @param string       $permissionCheckerId Container id of its EntityPermissions
	 * @param string       $tabsFactoryId       Container id of its tabs factory
	 * @param string       $showRouteName       Name of the route showing one entity
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $entityClass,
		public readonly string $repositoryId,
		public readonly string $element,
		public readonly string $tableElement,
		public readonly string $permissionCheckerId,
		public readonly string $tabsFactoryId,
		public readonly string $showRouteName
	) {
	}
}
