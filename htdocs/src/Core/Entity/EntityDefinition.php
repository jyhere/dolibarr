<?php

declare(strict_types=1);

namespace Dolibarr\Core\Entity;

/**
 * Describes an entity type a module exposes to the generic parts of the core (tabs, notes, documents...).
 *
 * Checklist to give an entity type the generic tabs (e.g. the notes on /{slug}/{id}/note), nothing to change in the core:
 * 1. Its entity class implements HasNotes.
 * 2. Its module's entities() returns this definition, with the legacy class and its file (loaded by
 *    LegacyObjectLoader::byElement(): require_once DOL_DOCUMENT_ROOT.$legacyClassFile, new $legacyClass($db), fetch($id)).
 *    The slug matches [a-z]+ (the route requirement).
 * 3. The service $repositoryId implements EntityRepository (find(int): ?object returning the HasNotes entity).
 * 4. The service $permissionCheckerId implements EntityPermissions (canRead, canEditNotes).
 * 5. The service $tabsFactoryId implements EntityTabs: chrome() for the tab ('note'), and
 *    legacyPageSettings('note') with the domains, hook contexts and doActions parameters of its legacy note page.
 * The services are registered in the container by the module's services(); EntityServices resolves and type-checks them.
 */
final class EntityDefinition
{
	/**
	 * @param string                            $slug                URL slug of the entity type (e.g. 'user')
	 * @param class-string                      $entityClass         Entity class
	 * @param string                            $repositoryId        Container id of its EntityRepository
	 * @param string                            $element             Legacy element code (e.g. 'user')
	 * @param string                            $tableElement        Legacy table element (e.g. 'user')
	 * @param string                            $permissionCheckerId Container id of its EntityPermissions
	 * @param string                            $tabsFactoryId       Container id of its EntityTabs
	 * @param string                            $showRouteName       Name of the route showing one entity
	 * @param class-string<\CommonObject>|null  $legacyClass         Legacy class of the element (e.g. \User::class)
	 * @param string|null                       $legacyClassFile     Its file under DOL_DOCUMENT_ROOT (e.g. '/user/class/user.class.php')
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $entityClass,
		public readonly string $repositoryId,
		public readonly string $element,
		public readonly string $tableElement,
		public readonly string $permissionCheckerId,
		public readonly string $tabsFactoryId,
		public readonly string $showRouteName,
		public readonly ?string $legacyClass = null,
		public readonly ?string $legacyClassFile = null
	) {
	}
}
