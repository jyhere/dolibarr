<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * The extra fields defined for an element type, in the current entity: the columns of <table>_extrafields the
 * ORM reads and writes for an entity with #[ExtraFields]. The legacy implementation reads llx_extrafields
 * (LegacyExtraFieldCatalog, Core/Legacy).
 */
interface ExtraFieldCatalog
{
	/**
	 * False when the extra fields are disabled for the instance (no name, and delete() leaves
	 * <table>_extrafields alone, like the legacy deleteExtraFields()).
	 */
	public function enabled(): bool;

	/**
	 * The names that can be selected, in their definition order, each a valid extra field column
	 * (Orm::isExtraFieldName()), exactly as defined (no case folding); [] when disabled.
	 *
	 * @param string $elementType The table of the entity without prefix (Dolibarr's table_element: 'user', 'societe')
	 * @return list<string>
	 */
	public function namesFor(string $elementType): array;
}
