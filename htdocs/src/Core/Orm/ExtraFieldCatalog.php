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
	 * The names that can be selected, in their definition order, each an unquoted SQL identifier
	 * (Orm::isExtraFieldName()), exactly as defined (no case folding).
	 *
	 * @param string $elementType The table of the entity without prefix (Dolibarr's table_element: 'user', 'societe')
	 * @return list<string>
	 */
	public function namesFor(string $elementType): array;
}
