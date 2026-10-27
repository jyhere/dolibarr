<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Attribute;

/**
 * Array property receiving the row of <table>_extrafields.
 *
 * EXPERIMENTAL: the extra field names are discovered from one row of <table>_extrafields, not from Dolibarr's
 * llx_extrafields definitions (per elementtype and entity). It must be replaced by a reading of llx_extrafields
 * before the first production entity uses it (User does not: its list reads the extra fields through the
 * legacy helpers).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class ExtraFields
{
}
