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

	public function testHostileNumericAndListFiltersRunWithoutError(): void
	{
		$this->insertUser('mvc_hostile_n', 1);

		foreach (['search_rowid' => '1) OR (1', 'search_country' => '1) OR (1', 'search_categ' => '1) OR (1', 'search_warehouse' => '1) OR (1', 'search_all' => "' OR ''='"] as $name => $value) {
			$page = $this->search(['search_login' => 'mvc_hostile_', $name => $value]);
			self::assertLessThanOrEqual(1, $page->total, $name);
		}
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
