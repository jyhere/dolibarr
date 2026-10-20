<?php

declare(strict_types=1);

namespace Dolibarr\Core\Entity;

/**
 * The legacy chrome of one entity's card pages (tabs, banner), for the generic tabs of the core (notes...).
 * Implemented by the service named by EntityDefinition::$tabsFactoryId.
 */
interface EntityTabs
{
	/**
	 * The chrome of the tab $activeTab of the entity. headerRowsHtml holds the <tr> rows the legacy tab page
	 * prints in a table under the banner, before the tab content (e.g. the Login row of user/note.php);
	 * '' for none.
	 *
	 * @param object $legacyObject The legacy object (\CommonObject) of the entity
	 * @param object $entity       The entity, as its repository returns it
	 * @return array{tabsHtml: string, bannerHtml: string, tabsEndHtml: string, headerRowsHtml: string}
	 */
	public function chrome(object $legacyObject, object $entity, string $activeTab): array;
}
