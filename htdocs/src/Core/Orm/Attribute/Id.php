<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Attribute;

/**
 * Identifier of the entity (one property). Its column is rowid unless a #[Column] names another one.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Id
{
}
