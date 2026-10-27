<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyCurrentUser;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Module\User\Entity\RightDefinition;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\RightsRepository;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\View\RightsChrome;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue of the rights tab (htdocs/user/perms.php l.171-406 and l.527-618).
 * Every test runs in a transaction rolled back in tearDown (the llx_rights_def fixes of the scan included).
 */
final class RightsRepositoryTest extends TestCase
{
	use OverridesLegacyConf;

	private LegacyContext $ctx;

	private Connection $connection;

	protected function setUp(): void
	{
		$this->ctx = LegacyContext::fromGlobals();
		$this->connection = new Connection($this->ctx->db);
		$this->disableUserAgendaEvents();
		$this->ctx->langs->loadLangs(['main', 'users', 'admin']);
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
		$this->restoreConf();
	}

	public function testCatalogListsEnabledModulesRightsSortedLikePerms(): void
	{
		$entity = (int) $this->ctx->conf->entity;
		$catalog = $this->repository()->catalogFor(1, $entity, false)->byModule;

		self::assertArrayHasKey('user', $catalog);
		$userRights = array_map(static fn (RightDefinition $definition): string => $definition->perms.'.'.$definition->subperms, $catalog['user']);
		self::assertContains('user.lire', $userRights);
		self::assertContains('self.creer', $userRights);
		foreach ($catalog as $module => $rights) {
			self::assertNotSame([], $rights);
			foreach ($rights as $right) {
				self::assertSame($module, $right->module);
				// l.529 and l.531-533
				self::assertStringStartsNotWith('tou', $right->label);
				self::assertStringEndsNotWith('_advance', $right->perms);
			}
		}

		// The legacy computation (l.527-618, dol_sort_array() included) gives the same rights in the same order
		self::assertSame($this->legacyOrder($entity), array_merge(...array_values(array_map(
			static fn (array $rights): array => array_map(static fn (RightDefinition $definition): int => $definition->id, $rights),
			$catalog
		))));
	}

	public function testAdvancedPermsAddTheAdvanceRights(): void
	{
		$entity = (int) $this->ctx->conf->entity;
		$advance = 0;
		foreach ($this->repository()->catalogFor(1, $entity, true)->byModule as $rights) {
			foreach ($rights as $right) {
				$advance += str_ends_with($right->perms, '_advance') ? 1 : 0;
			}
		}

		self::assertGreaterThan(0, $advance);
	}

	public function testGrantedFlagsReflectUserRights(): void
	{
		$entity = (int) $this->ctx->conf->entity;
		$target = $this->disposable();
		$read = $this->rightId('user', 'user', 'lire');
		$export = $this->rightId('user', 'user', 'export');
		$this->connection->execute('DELETE FROM @user_rights WHERE fk_user = :user', ['user' => $target->id]);
		$this->connection->execute('INSERT INTO @user_rights (entity, fk_user, fk_id) VALUES (:entity, :user, :right)', ['entity' => $entity, 'user' => $target->id, 'right' => $read]);
		// A group of the user with the export right (l.375-406)
		$group = $this->group();
		$this->connection->execute('INSERT INTO @usergroup_user (entity, fk_user, fk_usergroup) VALUES (:entity, :user, :group)', ['entity' => $entity, 'user' => $target->id, 'group' => $group]);
		$this->connection->execute('INSERT INTO @usergroup_rights (entity, fk_usergroup, fk_id) VALUES (:entity, :group, :right)', ['entity' => $entity, 'group' => $group, 'right' => $export]);

		$repo = $this->repository();
		$catalog = $repo->catalogFor($target->id, $entity, false);
		// perms.php's $permsgroupbyentity, read once with the catalogue
		self::assertSame([$entity => [$export]], $catalog->permsGroupByEntity);
		$byId = [];
		foreach ($catalog->byModule['user'] as $right) {
			$byId[$right->id] = $right;
		}

		self::assertTrue($byId[$read]->granted);
		self::assertFalse($byId[$read]->inheritedFromGroup);
		self::assertFalse($byId[$export]->granted);
		self::assertTrue($byId[$export]->inheritedFromGroup);
		$others = array_filter($byId, static fn (RightDefinition $definition): bool => $definition->id !== $read && $definition->id !== $export);
		self::assertSame([], array_filter($others, static fn (RightDefinition $definition): bool => $definition->granted || $definition->inheritedFromGroup));
		self::assertSame([$entity => [$export]], $repo->groupRightsByEntity($target->id, $entity));
		self::assertSame([$read], $repo->userRights($target->id, $entity));
	}

	public function testModuleMetaGivesPictoNameAndAdminFlag(): void
	{
		$meta = (new RightsChrome($this->ctx, $this->connection))->moduleMeta((int) $this->ctx->conf->entity);

		self::assertArrayHasKey('user', $meta);
		// modUser::$picto
		self::assertSame('group', $meta['user']['picto']);
		self::assertNotSame('', $meta['user']['name']);
		// modUser::$rights_admin_allowed
		self::assertTrue($meta['user']['adminAllowed']);
	}

	/**
	 * perms.php l.527-618 as is: the SQL, the family and positions completed, dol_sort_array(), the rights of
	 * modules without descriptor and the two vendor rights replaced by reception skipped.
	 *
	 * @return list<int>
	 */
	private function legacyOrder(int $entity): array
	{
		$modules = (new RightsChrome($this->ctx, $this->connection))->moduleDescriptors($entity);
		$familyinfo = RightsChrome::FAMILY_POSITIONS;
		// The rows as perms.php reads them (objects), the values bound
		$rows = array_map(static fn (array $row): object => (object) $row, $this->connection->fetchAll(
			"SELECT r.id, r.libelle as label, r.module, r.module_origin, r.perms, r.subperms, r.module_position, r.bydefault, r.family, r.family_position FROM @rights_def as r WHERE r.libelle NOT LIKE 'tou%' AND r.entity = :entity AND r.perms NOT LIKE '%_advance' ORDER BY r.family_position, r.module_position, r.right_position, r.module, r.id",
			['entity' => $entity]
		));
		foreach ($rows as $obj) {
			if (empty($obj->family)) {
				$obj->family = 'other';
			}
			if (!isset($familyinfo[$obj->family])) {
				$obj->family = 'external';
			}
			if (empty($obj->family_position)) {
				$obj->family_position = $familyinfo[$obj->family];
				$obj->module_position = (int) $obj->module_position < 100000 ? (int) $obj->module_position + 100000 : (int) $obj->module_position;
			}
			$obj->position = $obj->family_position.'_'.$obj->module_position.'_'.$obj->id;
		}
		$rows = dol_sort_array($rows, 'position');

		$ids = [];
		foreach ($rows as $obj) {
			if (empty($modules[$obj->module]) || (isModEnabled('reception') && RightsChrome::replacedByReception((string) $obj->module, (string) $obj->perms, (string) $obj->subperms))) {
				continue;
			}
			$ids[] = (int) $obj->id;
		}

		return $ids;
	}

	private function repository(): RightsRepository
	{
		return new RightsRepository($this->connection, new RightsChrome($this->ctx, $this->connection), new LegacyConfig($this->ctx));
	}

	private function rightId(string $module, string $perms, string $subperms): int
	{
		$id = $this->connection->fetchValue(
			'SELECT id FROM @rights_def WHERE module = :module AND perms = :perms AND subperms = :subperms AND entity = :entity',
			['module' => $module, 'perms' => $perms, 'subperms' => $subperms, 'entity' => (int) $this->ctx->conf->entity]
		);
		self::assertNotNull($id);

		return (int) $id;
	}

	private function disposable(): User
	{
		$loader = new LegacyObjectLoader($this->connection);
		$repo = new UserRepository($this->connection, (int) $this->ctx->conf->entity);
		$me = new LegacyCurrentUser($this->ctx->user);
		$service = new UserService($repo, new LegacyUserWriter($this->ctx, $loader, $this->connection), new EventDispatcher(), $this->connection, $me);

		return $service->create(new User(0, 1, 'mvc_test_'.uniqid(), lastname: 'Rights'), 'Secret-Pass-123!', []);
	}

	private function group(): int
	{
		require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
		$group = new \UserGroup($this->ctx->db);
		$group->name = 'mvc_test_group_'.uniqid();
		$group->entity = 1;

		return (int) $group->create();
	}
}
