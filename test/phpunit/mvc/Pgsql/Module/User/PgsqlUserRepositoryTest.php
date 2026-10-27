<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\PgsqlDriver;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Module\User\Repository\UserPage;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Repository\UserSearch;
use Dolibarr\Tests\Integration\Fixture\GlobalsRequest;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;
use PHPUnit\Framework\TestCase;

/**
 * UserRepository on PostgreSQL (pg_query_params): the card reads and the list query of list.php. Each test runs in
 * a rolled back transaction of the PostgreSQL connection.
 */
final class PgsqlUserRepositoryTest extends TestCase
{
	private Connection $connection;

	protected function setUp(): void
	{
		$this->connection = Connection::forLegacy(PgsqlDatabase::doliDb());
		self::assertInstanceOf(PgsqlDriver::class, $this->connection->driver());
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
	}

	public function testFindAdmin(): void
	{
		$admin = $this->repository()->find(1);

		self::assertNotNull($admin);
		self::assertSame('admin', $admin->login);
		self::assertTrue($admin->admin);
		self::assertSame(1, $admin->status);
		self::assertFalse($admin->hasApiKey);
		self::assertNull($this->repository()->find(999999));
	}

	public function testFindByLogin(): void
	{
		$admin = $this->repository()->findByLogin('admin');

		self::assertNotNull($admin);
		self::assertSame(1, $admin->id);
		self::assertNull($this->repository()->findByLogin("admin' OR '1'='1"));
	}

	public function testCounts(): void
	{
		$before = $this->repository()->countInEntity();
		$this->insertUser('mvc_pg_off', 0, 'Off');

		self::assertSame($before + 1, $this->repository()->countInEntity());
		self::assertSame($before, $this->repository()->countForQuota());
	}

	public function testSearchTextStatusSortAndPages(): void
	{
		$boss = $this->insertUser('mvc_pg_boss', 1, 'Zorro');
		$this->insertUser('mvc_pg_alpha', 1, 'Alpha', $boss);
		$this->insertUser('mvc_pg_beta', 1, 'Beta');
		$this->insertUser('mvc_pg_off', 0, 'Off');
		$this->connection->execute("UPDATE @user SET api_key = 'dolcrypt:pg' WHERE login = 'mvc_pg_beta'");

		// Text filter (ILIKE, as the legacy LIKE converted for PostgreSQL), default status: enabled users only
		$page = $this->search(['search_login' => 'MVC_PG_']);
		self::assertSame(['mvc_pg_alpha', 'mvc_pg_beta', 'mvc_pg_boss'], self::logins($page));
		self::assertSame(3, $page->total);
		self::assertSame('mvc_pg_boss', $page->items[0]->supervisorLogin);
		self::assertTrue($page->items[1]->user->hasApiKey);
		self::assertNotNull($page->items[0]->dateModification);

		// Status
		self::assertSame(['mvc_pg_off'], self::logins($this->search(['search_login' => 'mvc_pg_', 'search_status' => '0'])));
		self::assertSame(4, $this->search(['search_login' => 'mvc_pg_', 'search_status' => '-1'])->total);

		// Sort, on a column, a joined column and the computed date
		self::assertSame(['mvc_pg_boss', 'mvc_pg_beta', 'mvc_pg_alpha'], self::logins($this->search(['search_login' => 'mvc_pg_', 'sortfield' => 'u.lastname', 'sortorder' => 'DESC'])));
		self::assertCount(3, $this->search(['search_login' => 'mvc_pg_', 'sortfield' => 'co.label'])->items);
		self::assertCount(3, $this->search(['search_login' => 'mvc_pg_', 'sortfield' => 'date_modification', 'sortorder' => 'DESC'])->items);
		self::assertSame(['mvc_pg_alpha', 'mvc_pg_beta', 'mvc_pg_boss'], self::logins($this->search(['search_login' => 'mvc_pg_', 'sortfield' => 'u.pass_crypted'])));

		// Page 2 of 2 rows per page
		$second = $this->search(['search_login' => 'mvc_pg_', 'search_status' => '-1', 'limit' => '2', 'page' => '1']);
		self::assertSame(1, $second->page);
		self::assertSame(['mvc_pg_boss', 'mvc_pg_off'], self::logins($second));
		self::assertFalse($second->hasMore);
		self::assertSame(4, $second->total);

		// Numeric, list, phone and search_all filters
		self::assertSame(['mvc_pg_alpha'], self::logins($this->search(['search_login' => 'mvc_pg_', 'search_supervisor' => (string) $boss])));
		self::assertSame(['mvc_pg_alpha', 'mvc_pg_beta'], self::logins($this->search(['search_login' => 'mvc_pg_', 'search_rowid' => '>'.$boss])));
		self::assertSame(['mvc_pg_beta'], self::logins($this->search(['search_all' => 'mvc_pg_beta'])));
		self::assertSame(['mvc_pg_beta'], self::logins($this->search(['search_login' => 'mvc_pg_', 'search_api_key' => 'crypt:pg'])));
		self::assertSame(3, $this->search(['search_login' => 'mvc_pg_', 'search_categ' => '-2'])->total);
		self::assertSame(0, $this->search(['search_login' => 'mvc_pg_', 'search_phonepro' => '0612'])->total);
	}

	public function testLegacySqlOfTheListRunsOnPostgresql(): void
	{
		$this->insertUser('mvc_pg_alpha', 1, 'Alpha');

		$page = $this->search(['search_login' => 'mvc_pg_', 'sortfield' => 'date_modification']);

		self::assertStringStartsWith($page->sqlFields, $page->sql);
		self::assertStringStartsWith('SELECT DISTINCT ', $page->sqlFields);
		// What list_print_total.tpl.php and the printFieldListFooter hooks run through DoliDB
		$legacy = PgsqlDatabase::doliDb();
		$result = $legacy->query($page->sql);
		self::assertNotFalse($result, (string) $legacy->lasterror());
		self::assertSame(1, $legacy->num_rows($result));
	}

	private function repository(): UserRepository
	{
		return new UserRepository($this->connection, 1);
	}

	/**
	 * @param array<string, string> $query
	 */
	private function search(array $query): UserPage
	{
		$request = GlobalsRequest::create('GET', '/user', $query);
		$search = UserSearch::fromRequest($request, new LegacyConfig(LegacyContext::fromGlobals()), false, 20);

		return $this->repository()->search($search, null, null);
	}

	/**
	 * @return list<string>
	 */
	private static function logins(UserPage $page): array
	{
		return array_map(static fn ($row): string => $row->user->login, $page->items);
	}

	private function insertUser(string $login, int $status, string $lastname, ?int $supervisor = null): int
	{
		$this->connection->execute(
			'INSERT INTO @user (login, lastname, entity, statut, datec, fk_user) VALUES (:login, :lastname, 1, :status, :created, :supervisor)',
			['login' => $login, 'lastname' => $lastname, 'status' => $status, 'created' => '2026-01-01 10:00:00', 'supervisor' => $supervisor]
		);

		return $this->connection->lastInsertId('user');
	}
}
