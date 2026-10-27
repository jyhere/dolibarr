<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Attribute;

/**
 * Array property receiving the row of <table>_extrafields: name => raw value (string or null), [] when the
 * object has no row.
 *
 * The names are those Dolibarr defines in llx_extrafields for the mapping's table (elementtype) in entity 0 or
 * the current entity, separators excluded, kept exactly as defined (ExtraFieldCatalog, LegacyExtraFieldCatalog);
 * no definition (or MAIN_EXTRAFIELDS_DISABLED), no join. save() refuses a key that is not one of them.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class ExtraFields
{
}
