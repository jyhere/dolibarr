<?php

declare(strict_types=1);

namespace Dolibarr\Module\Notes\Repository;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;

/**
 * Writes the public and private notes of any legacy table, as CommonObject::update_note() does.
 */
final class NotesRepository
{
	private const FIELDS = ['note_public', 'note_private'];

	public function __construct(private readonly Connection $connection)
	{
	}

	/**
	 * CommonObject::update_note() l.4177-4210: the note (NULL when empty, as !empty($note)) and the modifier.
	 * The triggers are not run here: see NoteUpdated.
	 *
	 * @param string $field note_public or note_private
	 *
	 * @throws \InvalidArgumentException When $field is not a note field or $tableElement not a table name
	 * @throws DatabaseException
	 */
	public function update(string $tableElement, int $id, string $field, ?string $value, int $modifierId): void
	{
		if (!in_array($field, self::FIELDS, true)) {
			throw new \InvalidArgumentException('Not a note field: '.$field);
		}
		if (preg_match('/\A[a-z0-9_]+\z/', $tableElement) !== 1) {
			throw new \InvalidArgumentException('Not a table element: '.$tableElement);
		}

		// update_note() special cases: the private note of a product is its "note" column, a few tables name the modifier differently
		$column = $tableElement === 'product' && $field === 'note_private' ? 'note' : $field;
		$modifierColumn = match ($tableElement) {
			'actioncomm', 'adherent', 'advtargetemailing', 'cronjob', 'establishment' => 'fk_user_mod',
			'ecm_files' => 'fk_user_m',
			default => 'fk_user_modif',
		};

		// The table and the columns are identifiers checked above (never bound), the values are bound
		$this->connection->execute(
			'UPDATE '.$this->connection->prefix().$tableElement.' SET '.$column.' = :note, '.$modifierColumn.' = :modifier WHERE rowid = :id',
			['note' => !empty($value) ? $value : null, 'modifier' => $modifierId, 'id' => $id]
		);
	}
}
