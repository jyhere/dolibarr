<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Service\ValidationException;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use PHPUnit\Framework\TestCase;

/**
 * Every test runs in a transaction rolled back in tearDown (the legacy inner begin/commit only
 * decrement the DoliDB transaction counter).
 */
final class LegacyUserWriterTest extends TestCase
{
	use OverridesLegacyConf;

	private LegacyContext $ctx;

	private Connection $connection;

	protected function setUp(): void
	{
		$this->ctx = LegacyContext::fromGlobals();
		$this->connection = new Connection($this->ctx->db);
		$this->connection->begin();
		$this->disableUserAgendaEvents();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
		$this->restoreConf();
	}

	public function testInsertCreatesRowAndReturnsId(): void
	{
		$login = 'mvc_test_'.uniqid();
		$id = $this->writer()->insert($this->newUser($login), 1);

		self::assertGreaterThan(0, $id);
		$found = $this->repo()->find($id);
		self::assertNotNull($found);
		self::assertSame($login, $found->login);
		self::assertSame('Tester', $found->lastname);
		self::assertSame('QA', $found->job);
		self::assertSame('2026-01-15', $found->dateStartValidity?->format('Y-m-d'));
	}

	public function testInsertDuplicateLoginThrowsValidationException(): void
	{
		try {
			$this->writer()->insert($this->newUser((string) $GLOBALS['user']->login), 1);
			self::fail('ValidationException expected');
		} catch (ValidationException $e) {
			self::assertNotSame([], $e->messages);
			self::assertStringContainsString((string) $GLOBALS['user']->login, $e->messages[0]);
		}
	}

	public function testInsertWithoutLoginThrowsValidationException(): void
	{
		$this->expectException(ValidationException::class);
		$this->writer()->insert($this->newUser(''), 1);
	}

	public function testUpdateWritesMappedFieldsAndKeepsTheOthers(): void
	{
		$id = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);
		$this->connection->execute('UPDATE '.MAIN_DB_PREFIX."user SET openid = 'mvc-openid' WHERE rowid = ".$id);
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX."entrepot (ref, entity, datec, statut) VALUES ('MVC-WH-".uniqid()."', 1, NOW(), 1)");
		$warehouseId = $this->connection->lastInsertId('entrepot');
		$before = $this->repo()->find($id);
		self::assertNotNull($before);

		$this->writer()->update(new User(
			id: $id,
			entity: $before->entity,
			login: $before->login,
			lastname: 'Renamed',
			firstname: 'Jo',
			job: null,
			birth: new \DateTimeImmutable('1990-05-04', new \DateTimeZone('UTC')),
			salary: 1234.5,
			dateStartValidity: $before->dateStartValidity,
			officeFax: '0102',
			accountancyCode: '421MVC',
			warehouseId: $warehouseId
		), 1);

		$after = $this->repo()->find($id);
		self::assertNotNull($after);
		self::assertSame('Renamed', $after->lastname);
		self::assertSame('Jo', $after->firstname);
		self::assertSame('', $after->job ?? '');
		self::assertSame('1990-05-04', $after->birth?->format('Y-m-d'));
		self::assertSame(1234.5, $after->salary);
		self::assertSame($before->dateStartValidity?->format('Y-m-d'), $after->dateStartValidity?->format('Y-m-d'));
		self::assertSame('0102', $after->officeFax);
		self::assertSame('421MVC', $after->accountancyCode);
		self::assertSame($warehouseId, $after->warehouseId);
		$openid = $this->connection->fetchValue('SELECT openid FROM @user WHERE rowid = :id', ['id' => $id]);
		self::assertSame('mvc-openid', $openid, 'A field the entity does not carry keeps its value');
	}

	public function testUpdateDuplicateUniqueValueThrowsTheUpdateCanceledMessage(): void
	{
		$taken = 'mvc_test_'.uniqid();
		$this->writer()->insert($this->newUser($taken), 1);
		$id = $this->writer()->insert($this->newUser($taken.'b'), 1);

		// \User::update() checks the login in the entity it is given, the unique key is on the stored entity:
		// with another entity the check passes and the UPDATE hits uk_user_login (DB_ERROR_RECORD_ALREADY_EXISTS).
		try {
			$this->writer()->update(new User(id: $id, entity: 999, login: $taken, lastname: 'Dup'), 1);
			self::fail('ValidationException expected');
		} catch (ValidationException $e) {
			$this->ctx->langs->load('users');
			self::assertSame([$this->ctx->langs->trans('ErrorUpdateCanceledDueToDuplicatedUniqueValue', $taken)], $e->messages);
		}
		self::assertSame($taken.'b', $this->repo()->find($id)?->login);
	}

	public function testUpdateWritesForcePasswordChange(): void
	{
		$id = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);
		$before = $this->repo()->find($id);
		self::assertNotNull($before);
		self::assertFalse($before->forcePasswordChange);

		$this->writer()->update(new User(id: $id, entity: 1, login: $before->login, lastname: 'Tester', forcePasswordChange: true), 1);

		self::assertTrue($this->repo()->find($id)?->forcePasswordChange);
	}

	public function testUpdateSyncsTheContactLinkedBeforeNotTheNewOne(): void
	{
		$id = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX."socpeople (lastname, entity, datec, fk_user_creat) VALUES ('MVC contact', 1, NOW(), 1)");
		$contactId = $this->connection->lastInsertId('socpeople');

		// card.php l.497-652: update() syncs $object->contact_id, the fetched one; the new link is the raw UPDATE after
		$this->writer()->update(new User(id: $id, entity: 1, login: 'mvc_test_'.uniqid(), lastname: 'Overwrites', contactId: $contactId), 1);

		self::assertSame('MVC contact', $this->connection->fetchValue('SELECT lastname FROM @socpeople WHERE rowid = :id', ['id' => $contactId]));
		self::assertNull($this->repo()->find($id)?->contactId);
	}

	public function testLinkToAContactOfAnotherUserThrowsValidationException(): void
	{
		$first = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);
		$second = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX."socpeople (lastname, entity, datec, fk_user_creat) VALUES ('MVC contact', 1, NOW(), 1)");
		$contactId = $this->connection->lastInsertId('socpeople');
		$this->writer()->linkToContact($first, $contactId, 0);

		// uk_user_fk_socpeople: card.php l.680-683 shows $db->lasterror()
		$this->expectException(ValidationException::class);
		$this->writer()->linkToContact($second, $contactId, 0);
	}

	public function testIsCurrentPassword(): void
	{
		$id = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);
		$this->writer()->setPassword($id, 'Current-Pass-123!', false, 1);

		self::assertTrue($this->writer()->isCurrentPassword($id, 'Current-Pass-123!'));
		self::assertFalse($this->writer()->isCurrentPassword($id, 'Other-Pass-123!'));
	}

	public function testUpdateUnknownUserThrows(): void
	{
		$this->expectException(ValidationException::class);
		$this->writer()->update(new User(id: 999999, entity: 1, login: 'ghost'), 1);
	}

	public function testSetStatusDisablesAndFiresTriggerOnce(): void
	{
		$id = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);
		$this->setConf('MAIN_LOGEVENTS_USER_ENABLEDISABLE', 1);
		$before = $this->countEvents('USER_ENABLEDISABLE');

		$this->writer()->setStatus($id, false);

		self::assertSame(0, $this->repo()->find($id)?->status);
		self::assertSame($before + 1, $this->countEvents('USER_ENABLEDISABLE'));

		// Unchanged status: no write, no trigger
		$this->writer()->setStatus($id, false);
		self::assertSame($before + 1, $this->countEvents('USER_ENABLEDISABLE'));

		$this->writer()->setStatus($id, true);
		self::assertSame(1, $this->repo()->find($id)?->status);
	}

	public function testSetPasswordReturnsClearAndFlagsSessions(): void
	{
		$id = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);

		$pwd = $this->writer()->setPassword($id, '', false, 1);

		self::assertNotSame('', $pwd);
		$row = $this->connection->fetchOne('SELECT pass_crypted, flagdelsessionsbefore FROM @user WHERE rowid = :id', ['id' => $id]);
		self::assertNotNull($row);
		self::assertNotNull($row['flagdelsessionsbefore']);
		self::assertTrue(dol_verifyHash($pwd, (string) $row['pass_crypted']));

		self::assertSame('Given-Pass-123!', $this->writer()->setPassword($id, 'Given-Pass-123!', false, 1));
	}

	public function testDeleteRemovesTheUser(): void
	{
		$id = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);

		$this->writer()->delete($id, 1);

		self::assertNull($this->repo()->find($id));
	}

	public function testDeleteUnknownUserThrows(): void
	{
		$this->expectException(ValidationException::class);
		$this->writer()->delete(999999, 1);
	}

	public function testRightsGroupsAndCategories(): void
	{
		$id = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);
		$countRights = fn (): int => (int) $this->connection->fetchValue('SELECT COUNT(*) AS nb FROM @user_rights WHERE fk_user = :id', ['id' => $id]);
		$initial = $countRights();

		$this->writer()->addRights($id, null, 'user', 0);
		$withModule = $countRights();
		self::assertGreaterThan($initial, $withModule);
		$this->writer()->delRights($id, null, 'user', 0);
		self::assertLessThan($withModule, $countRights());

		require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
		$group = new \UserGroup($this->ctx->db);
		$group->name = 'mvc_test_group_'.uniqid();
		$group->entity = 1;
		$groupId = $group->create();
		self::assertGreaterThan(0, $groupId);
		$inGroup = fn (): int => (int) $this->connection->fetchValue('SELECT COUNT(*) AS nb FROM @usergroup_user WHERE fk_user = :id AND fk_usergroup = :group', ['id' => $id, 'group' => $groupId]);
		$this->writer()->setInGroup($id, $groupId, 1);
		self::assertSame(1, $inGroup());
		$this->writer()->removeFromGroup($id, $groupId, 1);
		self::assertSame(0, $inGroup());

		$this->writer()->setCategories($id, []);
		$this->writer()->reloadOwnRights();
		self::assertTrue((bool) $GLOBALS['user']->admin);
	}

	public function testLinkToContactReproducesTheCardUpdate(): void
	{
		$id = $this->writer()->insert($this->newUser('mvc_test_'.uniqid()), 1);
		// Raw fixtures: the legacy Societe/Contact create() raise PHP deprecations of their own
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX."societe (nom, entity, datec) VALUES ('MVC test company', 1, NOW())");
		$companyId = $this->connection->lastInsertId('societe');
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX."socpeople (lastname, fk_soc, entity, datec, fk_user_creat) VALUES ('MVC contact', ".$companyId.', 1, NOW(), 1)');
		$contactId = $this->connection->lastInsertId('socpeople');
		self::assertGreaterThan(0, $contactId);

		self::assertFalse($this->writer()->linkToContact($id, $contactId, 0));
		$linked = $this->repo()->find($id);
		self::assertSame($contactId, $linked?->contactId);
		self::assertSame($companyId, $linked?->companyId);

		self::assertFalse($this->writer()->linkToContact($id, 0, $companyId));
		$linked = $this->repo()->find($id);
		self::assertNull($linked?->contactId);
		self::assertSame($companyId, $linked?->companyId);

		self::assertFalse($this->writer()->linkToContact($id, 0, 0));
		$linked = $this->repo()->find($id);
		self::assertNull($linked?->contactId);
		self::assertNull($linked?->companyId);
		// A contact without company and a posted company: the company is cleared, the caller warns (card.php l.665-668)
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX."socpeople (lastname, entity, datec, fk_user_creat) VALUES ('MVC lonely', 1, NOW(), 1)");
		$lonely = $this->connection->lastInsertId('socpeople');
		self::assertTrue($this->writer()->linkToContact($id, $lonely, $companyId));
		self::assertNull($this->repo()->find($id)?->companyId);
	}

	private function newUser(string $login): User
	{
		return new User(
			id: 0,
			entity: 1,
			login: $login,
			lastname: 'Tester',
			firstname: 'Mvc',
			job: 'QA',
			dateStartValidity: new \DateTimeImmutable('2026-01-15', new \DateTimeZone('UTC'))
		);
	}

	private function writer(): LegacyUserWriter
	{
		return new LegacyUserWriter($this->ctx, new LegacyObjectLoader($this->connection), $this->connection);
	}

	private function repo(): UserRepository
	{
		return new UserRepository($this->connection, 1);
	}

	private function countEvents(string $type): int
	{
		return (int) $this->connection->fetchValue('SELECT COUNT(*) AS nb FROM @events WHERE type = :type', ['type' => $type]);
	}
}
