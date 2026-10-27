<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Attribute;

/**
 * Table of a mapped entity, without prefix ('user' for llx_user). The element is what getEntity() receives
 * for the multicompany filter, the table name by default.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Table
{
	public function __construct(
		public readonly string $name,
		public readonly ?string $element = null
	) {
	}
}
