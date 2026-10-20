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
		$this->connection->execute('UPDATE '.MAIN_DB_PREFIX."user SET office_fax = '0102' WHERE rowid = ".$id);
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
			dateStartValidity: $before->dateStartValidity
		), 1);

		$after = $this->repo()->find($id);
		self::assertNotNull($after);
		self::assertSame('Renamed', $after->lastname);
		self::assertSame('Jo', $after->firstname);
		self::assertSame('', $after->job ?? '');
		self::assertSame('1990-05-04', $after->birth?->format('Y-m-d'));
		self::assertSame(1234.5, $after->salary);
		self::assertSame($before->dateStartValidity?->format('Y-m-d'), $after->dateStartValidity?->format('Y-m-d'));
		$fax = $this->connection->fetchOne('SELECT office_fax FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$id);
		self::assertNotNull($fax);
		self::assertSame('0102', $fax->office_fax, 'A field the entity does not carry keeps its value');
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
		$row = $this->connection->fetchOne('SELECT pass_crypted, flagdelsessionsbefore FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$id);
		self::assertNotNull($row);
		self::assertNotNull($row->flagdelsessionsbefore);
		self::assertTrue(dol_verifyHash($pwd, (string) $row->pass_crypted));

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
		$countRights = fn (): int => (int) ($this->connection->fetchOne('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'user_rights WHERE fk_user = '.$id)->nb ?? 0);
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
		$inGroup = fn (): int => (int) ($this->connection->fetchOne('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'usergroup_user WHERE fk_user = '.$id.' AND fk_usergroup = '.$groupId)->nb ?? 0);
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
		$companyId = $this->connection->lastInsertId(MAIN_DB_PREFIX.'societe');
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX."socpeople (lastname, fk_soc, entity, datec, fk_user_creat) VALUES ('MVC contact', ".$companyId.', 1, NOW(), 1)');
		$contactId = $this->connection->lastInsertId(MAIN_DB_PREFIX.'socpeople');
		self::assertGreaterThan(0, $contactId);

		$this->writer()->linkToContact($id, $contactId, 0);
		$linked = $this->repo()->find($id);
		self::assertSame($contactId, $linked?->contactId);
		self::assertSame($companyId, $linked?->companyId);

		$this->writer()->linkToContact($id, 0, $companyId);
		$linked = $this->repo()->find($id);
		self::assertNull($linked?->contactId);
		self::assertSame($companyId, $linked?->companyId);

		$this->writer()->linkToContact($id, 0, 0);
		$linked = $this->repo()->find($id);
		self::assertNull($linked?->contactId);
		self::assertNull($linked?->companyId);
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
		$row = $this->connection->fetchOne('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX."events WHERE type = '".$type."'");
		self::assertNotNull($row);

		return (int) $row->nb;
	}
}
