<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Attribute;

/**
 * Array property receiving the row of <table>_extrafields.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class ExtraFields
{
}
