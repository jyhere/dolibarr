<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Module\User\Repository\UserPage;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Repository\UserSearch;
use Dolibarr\Tests\Integration\Fixture\GlobalsRequest;
use Dolibarr\Tests\Integration\Orm\Fixture\RecordingDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review Focus 1: a hostile value in a filter or a sort of the user list gives the result of today (no extra row,
 * no SQL error, the default sort). Each test runs in a rolled back transaction.
 */
final class UserRepositoryHostileFiltersTest extends TestCase
{
	private LegacyContext $ctx;

	private Connection $connection;

	protected function setUp(): void
	{
		$this->ctx = LegacyContext::fromGlobals();
		$this->connection = new Connection($this->ctx->db);
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
	}

	public function testInjectionInATextFilterFindsNothing(): void
	{
		$this->insertUser('mvc_hostile_a', 1);

		$page = $this->search(['search_login' => "a' OR 1=1 --"]);

		self::assertSame([], $page->items);
		self::assertSame(0, $page->total);
	}

	public function testInjectionInTheStatusIsTheDefaultStatus(): void
	{
		$this->insertUser('mvc_hostile_on', 1);
		$this->insertUser('mvc_hostile_off', 0);

		$hostile = $this->search(['search_login' => 'mvc_hostile_', 'search_status' => '1) OR (1']);
		$empty = $this->search(['search_login' => 'mvc_hostile_', 'search_status' => '']);

		self::assertSame(['mvc_hostile_on'], self::logins($empty));
		self::assertSame(self::logins($empty), self::logins($hostile));
		self::assertSame($empty->total, $hostile->total);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function hostileSorts(): array
	{
		return [
			'secret column' => ['u.pass_crypted'],
			'subquery' => ['(SELECT 1)'],
			'unknown joined column' => ['s.nonexistent'],
			'secret on a join' => ['u2.pass_crypted'],
		];
	}

	#[DataProvider('hostileSorts')]
	public function testHostileSortIsTheDefaultSort(string $sortField): void
	{
		$this->insertUser('mvc_hostile_b', 1, 'Zed');
		$this->insertUser('mvc_hostile_a', 1, 'Young');
		$this->insertUser('mvc_hostile_c', 1, 'Abel');

		$page = $this->search(['search_login' => 'mvc_hostile_', 'sortfield' => $sortField, 'sortorder' => 'DESC']);

		// u.login is the default sort field (UserSearch), the order asked is kept
		self::assertSame(['mvc_hostile_c', 'mvc_hostile_b', 'mvc_hostile_a'], self::logins($page));
	}

	public function testSupervisorListWithAnEmptyItemFiltersOnBoth(): void
	{
		$first = $this->insertUser('mvc_hostile_boss1', 1);
		$second = $this->insertUser('mvc_hostile_boss2', 1);
		$this->insertUser('mvc_hostile_sub1', 1, 'Sub', $first);
		$this->insertUser('mvc_hostile_sub2', 1, 'Sub', $second);
		$this->insertUser('mvc_hostile_sub3', 1, 'Sub', 1);

		$page = $this->search(['search_login' => 'mvc_hostile_', 'search_supervisor' => $first.',,'.$second]);

		self::assertSame(['mvc_hostile_sub1', 'mvc_hostile_sub2'], self::logins($page));
	}

	public function testEmployeeOutsideZeroOrOneMatchesNobody(): void
	{
		$this->insertUser('mvc_hostile_emp', 1);

		// u.employee = 2 in list.php (l.463), not a boolean cast to 1
		self::assertSame(0, $this->search(['search_login' => 'mvc_hostile_', 'search_employee' => '2'])->total);
		self::assertSame(1, $this->search(['search_login' => 'mvc_hostile_', 'search_employee' => '1'])->total);
	}

	/**
	 * @return array<string, array{string, string, int}>
	 */
	public static function hostileNumericFilters(): array
	{
		return [
			// mode 1 (l.452): the criteria '1)', 'OR', '(1' are ANDed, 'OR' is not a number: 1 = 2
			'rowid' => ['search_rowid', '1) OR (1', 0],
			// l.479 IN (sanitized): no integer in the list, IN (NULL) as before; a cast to 1 would find the user
			'country' => ['search_country', '1) OR (1', 0],
			// GETPOST intcomma empties a value with other characters (functions.lib.php l.1434): no category filter
			'categ' => ['search_categ', '1) OR (1', 1],
			// l.446 natural_search() of the words on fk_warehouse (none matches 1), then fk_warehouse = (int) 1
			'warehouse' => ['search_warehouse', '1) OR (1', 0],
			// The words "'", 'OR' and "''='" ANDed on the search_all columns: none holds them all
			'search_all' => ['search_all', "' OR ''='", 0],
		];
	}

	#[DataProvider('hostileNumericFilters')]
	public function testHostileNumericAndListFiltersGiveTheLegacyResult(string $name, string $value, int $expected): void
	{
		$id = $this->insertUser('mvc_hostile_n', 1);
		$this->connection->legacyExecute('UPDATE '.MAIN_DB_PREFIX.'user SET fk_country = 1, fk_warehouse = 1 WHERE rowid = '.$id);

		$page = $this->search(['search_login' => 'mvc_hostile_', $name => $value]);

		self::assertSame($expected, $page->total);
		self::assertCount($expected, $page->items);
	}

	public function testApiKeyIsAFilterNeverAColumn(): void
	{
		$this->insertUser('mvc_hostile_key', 1);
		$this->insertUser('mvc_hostile_nokey', 1);
		$this->connection->legacyExecute('UPDATE '.MAIN_DB_PREFIX."user SET api_key = 'dolcrypt:mvc-hostile-key' WHERE login = 'mvc_hostile_key'");

		$page = $this->search(['search_login' => 'mvc_hostile_', 'search_api_key' => 'mvc-hostile']);

		self::assertSame(['mvc_hostile_key'], self::logins($page));
		self::assertTrue($page->items[0]->user->hasApiKey);
		self::assertArrayNotHasKey('api_key', $page->items[0]->row);
		self::assertSame(0, $this->search(['search_login' => 'mvc_hostile_', 'search_api_key' => "' OR 1=1 --"])->total);
	}

	public function testHostileValuesAreBoundNeverWrittenInTheSql(): void
	{
		$recorder = new RecordingDriver($this->connection->driver());
		$connection = new Connection($this->ctx->db, $recorder);
		$request = GlobalsRequest::create('GET', '/user', ['search_login' => "a' OR 1=1 --", 'search_lastname' => 'x\' UNION SELECT pass_crypted', 'search_api_key' => "k' --"]);
		$search = UserSearch::fromRequest($request, new LegacyConfig($this->ctx), false, 20);

		(new UserRepository($connection, (int) $this->ctx->conf->entity))->search($search, null, null);
		(new UserRepository($connection, (int) $this->ctx->conf->entity))->findByLogin("admin' --");

		// The count, the list and the find run as prepared statements, their values bound
		self::assertCount(3, $recorder->statements);
		foreach ($recorder->statements as $sql) {
			self::assertStringNotContainsString("'", str_replace("<> ''", '', $sql), $sql);
			self::assertStringNotContainsString('UNION', $sql);
			self::assertDoesNotMatchRegularExpression('/\bpass_crypted\b/', $sql);
		}
	}

	/**
	 * list_print_total.tpl.php l.158-163 replaces $sqlfields by its sums and keeps the ORDER BY of $sql: a sort on a
	 * computed column must order by its expression, not by an alias the sums drop (import_key would also be ambiguous
	 * with ef.import_key). date_modification is ordered by its alias as list.php l.553 does, so the grand total of
	 * that sort fails on MariaDB as it does on list.php.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function grandTotalSorts(): array
	{
		return [
			'login' => ['u.login', true],
			'import key' => ['u.import_key', true],
			'api key' => ['u.api_key', true],
			'salary' => ['u.salary', true],
			'date modification (legacy alias)' => ['date_modification', false],
		];
	}

	#[DataProvider('grandTotalSorts')]
	public function testGrandTotalSqlOfTheListRuns(string $sortField, bool $runs): void
	{
		$id = $this->insertUser('mvc_hostile_paid', 1);
		$this->connection->legacyExecute('UPDATE '.MAIN_DB_PREFIX.'user SET salary = 1500, import_key = \'imp\' WHERE rowid = '.$id);

		$page = $this->search(['search_login' => 'mvc_hostile_', 'sortfield' => $sortField]);
		self::assertStringStartsWith($page->sqlFields, $page->sql);
		$sql = (string) preg_replace('/GROUP BY .*$/', '', 'SELECT sum(u.salary) as total'.substr($page->sql, strlen($page->sqlFields)));
		$result = $this->ctx->db->query($sql);

		if (!$runs) {
			self::assertFalse($result);
			self::assertStringContainsString('date_modification', (string) $this->ctx->db->lasterror());

			return;
		}
		self::assertNotFalse($result, (string) $this->ctx->db->lasterror());
		$row = $this->ctx->db->fetch_array($result);
		self::assertIsArray($row);
		self::assertEqualsWithDelta(1500.0, (float) $row['total'], 0.001);
		// The secret stays out of the results: the ApiKey sort orders by the flag expression
		self::assertDoesNotMatchRegularExpression('/ORDER BY u\.api_key\b/', $page->sql);
	}

	public function testWritesAreRefused(): void
	{
		$repository = new UserRepository($this->connection, (int) $this->ctx->conf->entity);
		$admin = $repository->find(1);
		self::assertNotNull($admin);
		$attempts = [
			'save' => static fn () => $repository->save($admin),
			'modify' => static fn () => $repository->modify($admin, ['lastname' => 'Changed']),
			'delete' => static fn () => $repository->delete($admin),
		];
		foreach ($attempts as $method => $attempt) {
			try {
				$attempt();
				self::fail($method.'() accepted');
			} catch (\LogicException $e) {
				self::assertStringContainsString('read-only', $e->getMessage(), $method);
			}
		}
		self::assertSame($admin->lastname, $repository->find(1)?->lastname);
	}

	public function testDateModificationSortUsesTheExtraFieldsDate(): void
	{
		$old = $this->insertUser('mvc_hostile_old', 1);
		$this->insertUser('mvc_hostile_new', 1);
		$this->connection->legacyExecute('UPDATE '.MAIN_DB_PREFIX."user SET tms = '2001-01-01 00:00:00' WHERE login LIKE 'mvc\\_hostile\\_%'");
		$this->connection->legacyExecute('INSERT INTO '.MAIN_DB_PREFIX."user_extrafields (fk_object, tms) VALUES (".$old.", '2030-01-01 00:00:00')");

		$page = $this->search(['search_login' => 'mvc_hostile_', 'sortfield' => 'date_modification', 'sortorder' => 'DESC']);

		self::assertSame(['mvc_hostile_old', 'mvc_hostile_new'], self::logins($page));
		self::assertSame('2030-01-01', $page->items[0]->dateModification?->format('Y-m-d'));
	}

	/**
	 * @param array<string, string> $query
	 */
	private function search(array $query): UserPage
	{
		$request = GlobalsRequest::create('GET', '/user', $query);
		$search = UserSearch::fromRequest($request, new LegacyConfig($this->ctx), false, 20);

		return (new UserRepository($this->connection, (int) $this->ctx->conf->entity))->search($search, null, null);
	}

	/**
	 * @return list<string>
	 */
	private static function logins(UserPage $page): array
	{
		return array_map(static fn ($row): string => $row->user->login, $page->items);
	}

	private function insertUser(string $login, int $status, string $lastname = 'Hostile', ?int $supervisor = null): int
	{
		$this->connection->legacyExecute('INSERT INTO '.MAIN_DB_PREFIX."user (login, lastname, entity, statut, datec, fk_user) VALUES ('".$login."', '".$lastname."', 1, ".$status.', NOW(), '.($supervisor ?? 'NULL').')');

		return $this->connection->lastInsertId('user');
	}
}
