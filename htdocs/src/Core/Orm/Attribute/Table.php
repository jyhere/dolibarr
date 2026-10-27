<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Attribute;

/**
 * Table of a mapped entity, without prefix ('user' for llx_user). The element is what getEntity() receives
 * for the multicompany filter, the table name by default.
 *
 * Every mapping is entity scoped by default: it must map the `entity` column, and every read, UPDATE and
 * DELETE is restricted to getEntity(element). `entityScoped: false` is the only way to declare a table without
 * multicompany scope (it then must not map an `entity` column); MappingReader refuses any other combination.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Table
{
	public function __construct(
		public readonly string $name,
		public readonly ?string $element = null,
		public readonly bool $entityScoped = true
	) {
	}
}
