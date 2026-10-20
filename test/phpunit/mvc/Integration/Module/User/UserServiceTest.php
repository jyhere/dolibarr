<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyCurrentUser;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Logger;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Event\UserCreated;
use Dolibarr\Module\User\Event\UserDeleted;
use Dolibarr\Module\User\Event\UserGroupsChanged;
use Dolibarr\Module\User\Event\UserPasswordChanged;
use Dolibarr\Module\User\Event\UserRightsChanged;
use Dolibarr\Module\User\Event\UserStatusChanged;
use Dolibarr\Module\User\Event\UserUpdated;
use Dolibarr\Module\User\Listener\LegacyTriggerBridge;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\Service\ValidationException;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
	use OverridesLegacyConf;

	private LegacyContext $ctx;

	private Connection $connection;

	private EventDispatcher $events;

	/** @var list<object> */
	private array $spied = [];

	private bool $outerTransaction = true;

	protected function setUp(): void
	{
		$this->ctx = LegacyContext::fromGlobals();
		$this->connection = new Connection($this->ctx->db);
		$this->events = new EventDispatcher();
		(new LegacyTriggerBridge(new LegacyTriggers($this->ctx), $this->ctx, new LegacyObjectLoader($this->connection), new Logger()))->register($this->events);
		foreach ([UserCreated::class, UserUpdated::class, UserDeleted::class, UserStatusChanged::class, UserPasswordChanged::class, UserRightsChanged::class, UserGroupsChanged::class] as $class) {
			$this->events->addListener($class, function (object $e): void {
				$this->spied[] = $e;
			});
		}
		$this->disableUserAgendaEvents();
	}

	protected function tearDown(): void
	{
		if ($this->outerTransaction) {
			$this->connection->rollback();
		}
		$this->restoreConf();
	}

	public function testServiceCreateDispatchesUserCreatedAndBridgeStaysSilent(): void
	{
		$this->begin();
		$this->setConf('MAIN_LOGEVENTS_USER_CREATE', 1);
		$before = $this->countEvents('USER_CREATE');
		$login = 'mvc_test_'.uniqid();

		$created = $this->service()->create($this->newUser($login), 'Secret-Pass-123!', []);

		self::assertSame($login, $created->login);
		self::assertCount(1, $this->spied);
		self::assertInstanceOf(UserCreated::class, $this->spied[0]);
		self::assertTrue($this->spied[0]->legacyTriggerFired);
		self::assertSame($created->id, $this->spied[0]->user->id);
		self::assertSame($before + 1, $this->countEvents('USER_CREATE'), 'Only the legacy trigger logs USER_CREATE, not the bridge');
		$row = $this->connection->fetchOne('SELECT pass_crypted FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$created->id);
		self::assertTrue(dol_verifyHash('Secret-Pass-123!', (string) $row?->pass_crypted));
	}

	public function testServiceRollsBackWhenListenerThrows(): void
	{
		// No outer transaction here: a nested DoliDB rollback only decrements the counter, the
		// rollback of the service must be the real one to be observable.
		$this->outerTransaction = false;
		self::assertSame(0, (int) $this->ctx->db->transaction_opened);
		$this->events->addListener(UserCreated::class, static function (): void {
			throw new \RuntimeException('listener failure');
		});
		$login = 'mvc_test_'.uniqid();

		try {
			$this->service()->create($this->newUser($login), null, []);
			self::fail('The listener exception must be rethrown');
		} catch (\RuntimeException $e) {
			self::assertSame('listener failure', $e->getMessage());
		}

		self::assertSame(0, (int) $this->ctx->db->transaction_opened);
		self::assertNull($this->repo()->findByLogin($login));
	}

	public function testValidationErrorRollsBackAndRethrows(): void
	{
		$this->begin();
		$this->expectException(ValidationException::class);
		try {
			$this->service()->create($this->newUser((string) $GLOBALS['user']->login), null, []);
		} finally {
			self::assertSame([], $this->spied);
			self::assertSame(1, (int) $this->ctx->db->transaction_opened, 'The service transaction is closed');
		}
	}

	public function testUpdateStatusPasswordRightsGroupsAndDeleteDispatchTheirEvents(): void
	{
		$this->begin();
		$service = $this->service();
		$created = $service->create($this->newUser('mvc_test_'.uniqid()), null, []);
		$this->spied = [];

		$updated = $service->update(new User(id: $created->id, entity: $created->entity, login: $created->login, lastname: 'Updated'), [], 0, 0)->user;
		self::assertSame('Updated', $updated->lastname);
		self::assertInstanceOf(UserUpdated::class, $this->spied[0]);
		self::assertSame('Tester', $this->spied[0]->before->lastname);
		self::assertSame('Updated', $this->spied[0]->after->lastname);

		$disabled = $service->setStatus($created->id, false);
		self::assertSame(0, $disabled->status);
		self::assertInstanceOf(UserStatusChanged::class, $this->spied[1]);
		self::assertFalse($this->spied[1]->enabled);

		$clear = $service->resetPassword($created->id, false);
		self::assertNotSame('', $clear);
		self::assertInstanceOf(UserPasswordChanged::class, $this->spied[2]);
		self::assertFalse($this->spied[2]->sent);
		$service->setPasswordFromForm($created->id, 'Form-Pass-123!');
		$row = $this->connection->fetchOne('SELECT pass_crypted FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$created->id);
		self::assertTrue(dol_verifyHash('Form-Pass-123!', (string) $row?->pass_crypted));

		$service->addRights($created->id, null, 'user');
		$service->delRights($created->id, null, 'user');
		self::assertInstanceOf(UserRightsChanged::class, $this->spied[4]);
		self::assertInstanceOf(UserRightsChanged::class, $this->spied[5]);

		require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
		$group = new \UserGroup($this->ctx->db);
		$group->name = 'mvc_test_group_'.uniqid();
		$group->entity = 1;
		$groupId = $group->create();
		$service->addToGroup($created->id, $groupId);
		$service->removeFromGroup($created->id, $groupId);
		self::assertInstanceOf(UserGroupsChanged::class, $this->spied[6]);
		self::assertInstanceOf(UserGroupsChanged::class, $this->spied[7]);

		$service->delete($created->id);
		self::assertInstanceOf(UserDeleted::class, $this->spied[8]);
		self::assertSame($created->login, $this->spied[8]->login);
		self::assertNull($this->repo()->find($created->id));
		foreach ($this->spied as $event) {
			self::assertTrue($event->legacyTriggerFired ?? false, $event::class);
		}
		self::assertSame(1, (int) $this->ctx->db->transaction_opened);
	}

	private function begin(): void
	{
		$this->connection->begin();
	}

	private function service(): UserService
	{
		$user = $this->ctx->user;
		self::assertNotNull($user);

		return new UserService(
			$this->repo(),
			new LegacyUserWriter($this->ctx, new LegacyObjectLoader($this->connection), $this->connection),
			$this->events,
			$this->connection,
			new LegacyCurrentUser($user)
		);
	}

	private function repo(): UserRepository
	{
		return new UserRepository($this->connection, 1);
	}

	private function newUser(string $login): User
	{
		return new User(id: 0, entity: 1, login: $login, lastname: 'Tester', firstname: 'Mvc');
	}

	private function countEvents(string $type): int
	{
		$row = $this->connection->fetchOne('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX."events WHERE type = '".$type."'");
		self::assertNotNull($row);

		return (int) $row->nb;
	}
}
