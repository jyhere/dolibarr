<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\Notes;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Module\Notes\Repository\NotesRepository;
use PHPUnit\Framework\TestCase;

/**
 * The UPDATE of CommonObject::update_note(), in a transaction rolled back in tearDown.
 */
final class NotesRepositoryTest extends TestCase
{
	private Connection $connection;

	protected function setUp(): void
	{
		$this->connection = new Connection(LegacyContext::fromGlobals()->db);
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
	}

	public function testUpdateWritesTheNoteAndTheModifier(): void
	{
		$repo = new NotesRepository($this->connection);

		$repo->update('user', 1, 'note_public', '<b>pub</b> l\'été', 1);
		$repo->update('user', 1, 'note_private', 'priv', 1);

		$row = $this->row(1);
		self::assertSame('<b>pub</b> l\'été', $row->note_public);
		self::assertSame('priv', $row->note_private);
		self::assertSame(1, (int) $row->fk_user_modif);
	}

	public function testEmptyValueIsStoredAsNull(): void
	{
		$repo = new NotesRepository($this->connection);
		$repo->update('user', 1, 'note_public', 'x', 1);

		// update_note(): !empty($note) ? quoted : NULL ('0' included)
		foreach (['', null, '0'] as $empty) {
			$repo->update('user', 1, 'note_public', $empty, 1);
			self::assertNull($this->row(1)->note_public);
		}
	}

	public function testUnknownFieldIsRefused(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new NotesRepository($this->connection))->update('user', 1, 'login', 'x', 1);
	}

	public function testBadTableIsRefused(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new NotesRepository($this->connection))->update('user; DROP', 1, 'note_public', 'x', 1);
	}

	private function row(int $id): object
	{
		$row = $this->connection->fetchOne('SELECT note_public, note_private, fk_user_modif FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$id);
		self::assertNotNull($row);

		return $row;
	}
}
