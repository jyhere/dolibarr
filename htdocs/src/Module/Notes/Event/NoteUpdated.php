<?php

declare(strict_types=1);

namespace Dolibarr\Module\Notes\Event;

/**
 * A note of an entity was written (inside the transaction of the write). Carries ids, never objects.
 */
final class NoteUpdated
{
	/**
	 * @param string $element            Legacy element code of the entity (e.g. 'user')
	 * @param string $tableElement       Its legacy table
	 * @param string $field              note_public or note_private
	 * @param bool   $legacyTriggerFired Whether the legacy <ELEMENT>_MODIFY trigger already ran
	 */
	public function __construct(
		public readonly string $element,
		public readonly string $tableElement,
		public readonly int $id,
		public readonly string $field,
		public readonly bool $legacyTriggerFired = false
	) {
	}
}
