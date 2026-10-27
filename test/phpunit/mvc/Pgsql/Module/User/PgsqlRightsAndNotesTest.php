<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Module\Notes\Repository\NotesRepository;
use Dolibarr\Module\User\Entity\RightDefinition;
use Dolibarr\Module\User\Repository\RightsRepository;
use Dolibarr\Module\User\View\RightsChrome;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The rights tab (RightsChrome, RightsRepository) and the notes (NotesRepository) on PostgreSQL, through bound
 * statements of PgsqlDriver ('$1' placeholders in lastquery, never the values). The legacy context is the one of
 * the MariaDB website with the PostgreSQL DoliDB: the module descriptors write the rights of the enabled modules
 * into its llx_rights_def. Each test runs in a rolled back transaction of the PostgreSQL connection.
 */
final class PgsqlRightsAndNotesTest extends TestCase
{
	private LegacyContext $ctx;

	private Connection $connection;

	protected function setUp(): void
	{
		$globals = LegacyContext::fromGlobals();
		$this->ctx = new LegacyContext($globals->conf, PgsqlDatabase::doliDb(), $globals->langs, $globals->user, $globals->hookmanager);
		$this->ctx->langs->loadLangs(['main', 'users', 'admin']);
		$this->connection = Connection::forLegacy($this->ctx->db);
		self::assertInstanceOf(PgsqlDriver::class, $this->connection->driver());
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
	}

	public function testCatalogOfTheAdmin(): void
	{
		$catalog = $this->repository()->catalogFor(1, 1, false)->byModule;

		self::assertStringContainsString('$1', (string) $this->ctx->db->lastquery);
		self::assertArrayHasKey('user', $catalog);
		$userRights = array_map(static fn (RightDefinition $definition): string => $definition->perms.'.'.$definition->subperms, $catalog['user']);
		self::assertContains('user.lire', $userRights);
		foreach ($catalog as $module => $rights) {
			foreach ($rights as $right) {
				self::assertSame($module, $right->module);
				self::assertStringStartsNotWith('tou', $right->label);
				self::assertStringEndsNotWith('_advance', $right->perms);
				self::assertGreaterThan(0, $right->familyPosition);
			}
		}
		// The descriptors fixed the positions and families of llx_rights_def (perms.php l.258-330)
		self::assertSame(0, (int) $this->connection->fetchValue("SELECT COUNT(*) FROM @rights_def WHERE entity = 1 AND module = 'user' AND (family IS NULL OR family = '')"));

		$advanced = $this->repository()->catalogFor(1, 1, true)->byModule;
		self::assertGreaterThanOrEqual(count($catalog['user']), count($advanced['user']));
	}

	public function testCatalogFiltersAreCaseInsensitiveLikeTheLegacyIlike(): void
	{
		// perms.php writes LIKE, which DoliDBPgsql rewrites to ILIKE: an upper-case 'Tou…' label and an
		// '…_ADVANCE' perms are filtered on PostgreSQL exactly as on MySQL (a plain LIKE would keep them)
		$fixtures = [
			999901 => ['Tous les droits mvc', 'mvc_tou'],
			999902 => ['Advanced mvc right', 'mvc_ADVANCE'],
			999903 => ['Visible mvc right', 'mvc_visible'],
		];
		foreach ($fixtures as $id => [$label, $perms]) {
			$this->connection->execute(
				"INSERT INTO @rights_def (id, entity, libelle, module, module_position, family, family_position, perms, subperms, type, bydefault) VALUES (:id, 1, :label, 'user', 5, 'hr', 0, :perms, NULL, 'r', 0)",
				['id' => $id, 'label' => $label, 'perms' => $perms]
			);
		}

		$standardIds = $this->userRightIds($this->repository()->catalogFor(1, 1, false)->byModule);
		$advancedIds = $this->userRightIds($this->repository()->catalogFor(1, 1, true)->byModule);

		self::assertContains(999903, $standardIds);
		self::assertNotContains(999901, $standardIds, "NOT ILIKE 'tou%' excludes 'Tous…'");
		self::assertNotContains(999902, $standardIds, "NOT ILIKE '%_advance' excludes 'mvc_ADVANCE'");
		self::assertContains(999903, $advancedIds);
		self::assertContains(999902, $advancedIds);
		self::assertNotContains(999901, $advancedIds);
	}

	public function testDescriptorsOfAnotherEntityReadItsConstants(): void
	{
		// Not the current entity: the MAIN_MODULE_* constants come from llx_const (entity IN (0, :entity))
		$modules = (new RightsChrome($this->ctx, $this->connection))->moduleDescriptors(2);

		self::assertArrayHasKey('user', $modules);
		self::assertStringContainsString('$1', (string) $this->ctx->db->lastquery);
	}

	public function testGrantedAndGroupRights(): void
	{
		$repository = $this->repository();
		$repository->catalogFor(1, 1, false);
		$read = $this->rightId('lire');
		$export = $this->rightId('export');
		$userId = $this->insert('INSERT INTO @user (login, lastname, entity, statut, datec) VALUES (:login, :lastname, 1, 1, :created)', ['login' => 'mvc_pg_rights', 'lastname' => 'Rights', 'created' => '2026-01-01 10:00:00'], 'user');
		$groupId = $this->insert('INSERT INTO @usergroup (nom, entity, datec) VALUES (:name, 1, :created)', ['name' => 'mvc_pg_group', 'created' => '2026-01-01 10:00:00'], 'usergroup');
		$this->connection->execute('INSERT INTO @user_rights (entity, fk_user, fk_id) VALUES (1, :user, :right)', ['user' => $userId, 'right' => $read]);
		$this->connection->execute('INSERT INTO @usergroup_user (entity, fk_user, fk_usergroup) VALUES (1, :user, :group)', ['user' => $userId, 'group' => $groupId]);
		$this->connection->execute('INSERT INTO @usergroup_rights (entity, fk_usergroup, fk_id) VALUES (1, :group, :right)', ['group' => $groupId, 'right' => $export]);

		$catalog = $repository->catalogFor($userId, 1, false);

		self::assertSame([1 => [$export]], $catalog->permsGroupByEntity);
		self::assertSame([$read], $repository->userRights($userId, 1));
		self::assertSame([1 => [$export]], $repository->groupRightsByEntity($userId, 1));
		$byId = [];
		foreach ($catalog->byModule['user'] as $right) {
			$byId[$right->id] = $right;
		}
		self::assertTrue($byId[$read]->granted);
		self::assertFalse($byId[$read]->inheritedFromGroup);
		self::assertFalse($byId[$export]->granted);
		self::assertTrue($byId[$export]->inheritedFromGroup);
	}

	public function testNoteUpdatedThenRead(): void
	{
		$notes = new NotesRepository($this->connection);

		$notes->update('user', 1, 'note_public', "<b>pub</b> l'été \\ :x ?", 1);
		$notes->update('user', 1, 'note_private', 'mvc secret', 1);

		$lastQuery = (string) $this->ctx->db->lastquery;
		self::assertStringContainsString('$1', $lastQuery);
		self::assertStringNotContainsString('mvc secret', $lastQuery);
		$row = $this->connection->fetchOne('SELECT note_public, note_private, fk_user_modif FROM @user WHERE rowid = 1');
		self::assertNotNull($row);
		self::assertSame("<b>pub</b> l'été \\ :x ?", $row['note_public']);
		self::assertSame('mvc secret', $row['note_private']);
		self::assertSame(1, (int) $row['fk_user_modif']);

		$notes->update('user', 1, 'note_public', '0', 1);
		self::assertNull($this->connection->fetchValue('SELECT note_public FROM @user WHERE rowid = 1'));
	}

	private function repository(): RightsRepository
	{
		return new RightsRepository($this->connection, new RightsChrome($this->ctx, $this->connection), new LegacyConfig($this->ctx));
	}

	/**
	 * @param array<string, list<RightDefinition>> $catalog
	 * @return list<int>
	 */
	private function userRightIds(array $catalog): array
	{
		return array_map(static fn (RightDefinition $definition): int => $definition->id, $catalog['user'] ?? []);
	}

	private function rightId(string $subperms): int
	{
		$id = $this->connection->fetchValue(
			"SELECT id FROM @rights_def WHERE module = 'user' AND perms = 'user' AND subperms = :subperms AND entity = 1",
			['subperms' => $subperms]
		);
		self::assertNotNull($id, 'user.user.'.$subperms);

		return (int) $id;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function insert(string $sql, array $params, string $table): int
	{
		$this->connection->execute($sql, $params);

		return $this->connection->lastInsertId($table);
	}
}
