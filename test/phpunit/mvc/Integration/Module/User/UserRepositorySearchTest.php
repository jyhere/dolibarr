<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Repository\UserSearch;
use Dolibarr\Tests\Integration\Fixture\GlobalsRequest;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use PHPUnit\Framework\TestCase;

/**
 * UserRepository::search(), the query of htdocs/user/list.php l.398-559. Each test runs in a rolled back transaction.
 */
final class UserRepositorySearchTest extends TestCase
{
	use OverridesLegacyConf;

	private LegacyContext $ctx;

	private Connection $connection;

	/** @var array<mixed> */
	private array $get = [];

	/** @var array<mixed> */
	private array $post = [];

	protected function setUp(): void
	{
		$this->get = $_GET;
		$this->post = $_POST;
		$this->ctx = LegacyContext::fromGlobals();
		$this->connection = new Connection($this->ctx->db);
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
		$this->restoreConf();
		$_GET = $this->get;
		$_POST = $this->post;
	}

	public function testSearchFindsAdminByLogin(): void
	{
		$page = $this->search(['search_login' => 'admin']);

		$logins = array_map(static fn ($row): string => $row->user->login, $page->items);
		self::assertContains('admin', $logins);
		foreach ($logins as $login) {
			self::assertStringContainsStringIgnoringCase('admin', $login);
		}
		self::assertSame(count($page->items), $page->total);
		self::assertFalse($page->hasMore);
	}

	public function testCountMatchesItems(): void
	{
		$this->insertUser('mvc_list_a', 1);
		$this->insertUser('mvc_list_b', 1);
		$this->insertUser('mvc_list_c', 1);

		$all = $this->search(['search_login' => 'mvc_list_', 'limit' => '1000']);
		self::assertSame(3, $all->total);
		self::assertCount(3, $all->items);
		self::assertFalse($all->hasMore);

		// list.php l.558: limit + 1 rows read, the extra one only tells a next page exists
		$first = $this->search(['search_login' => 'mvc_list_', 'limit' => '2', 'sortfield' => 'u.login', 'sortorder' => 'DESC']);
		self::assertSame(3, $first->total);
		self::assertSame(['mvc_list_c', 'mvc_list_b'], array_map(static fn ($row): string => $row->user->login, $first->items));
		self::assertTrue($first->hasMore);

		// l.548-551: a page beyond the total shows the first one
		$beyond = $this->search(['search_login' => 'mvc_list_', 'limit' => '2', 'page' => '7']);
		self::assertSame(0, $beyond->page);
		self::assertCount(2, $beyond->items);

		// l.536: MAIN_DISABLE_FULL_SCANLIST, no count
		$this->setConf('MAIN_DISABLE_FULL_SCANLIST', 1);
		self::assertSame(-1, $this->search(['search_login' => 'mvc_list_'])->total);
	}

	public function testStatusFilterDefaultIsEnabled(): void
	{
		$this->insertUser('mvc_list_off', 0);

		// list.php l.219-221: no status asked, enabled users only
		self::assertSame(0, $this->search(['search_login' => 'mvc_list_off'])->total);
		self::assertSame(1, $this->search(['search_login' => 'mvc_list_off', 'search_status' => '0'])->total);
		self::assertSame(1, $this->search(['search_login' => 'mvc_list_off', 'search_status' => '-1'])->total);
		// search_all disables the default status
		self::assertSame(1, $this->search(['search_all' => 'mvc_list_off'])->total);
	}

	public function testHostileParametersDoNotBreakTheQuery(): void
	{
		$page = $this->search(['sortfield' => 'u.pass_crypted', 'sortorder' => 'DESC;', 'limit' => '0', 'page' => '-3', 'search_status' => '1) OR (1=1', 'search_country' => '1) OR (1', 'search_supervisor' => '1,2', 'search_rowid' => "1'"]);

		self::assertSame(0, $page->page);
		self::assertGreaterThanOrEqual(0, $page->total);
	}

	public function testJoinedColumnsAndFragments(): void
	{
		$boss = $this->insertUser('mvc_list_boss', 1, 'Boss');
		$this->insertUser('mvc_list_sub', 1, 'Sub', $boss);

		$page = $this->search(['search_login' => 'mvc_list_sub']);
		self::assertCount(1, $page->items);
		$row = $page->items[0];
		self::assertSame('mvc_list_boss', $row->supervisorLogin);
		self::assertSame('Boss', $row->supervisorLastname);
		self::assertSame('mvc_list_sub', $row->row['login'] ?? null);
		self::assertArrayNotHasKey('api_key', $row->row);
		self::assertNotNull($row->dateModification);

		// The fragments of the printFieldListSelect/From/Where hooks go into the query (list.php l.415-532)
		$page = $this->search(['search_login' => 'mvc_list_'], ['select' => ', 42 as hooked', 'from' => '', 'where' => " AND u.login = 'mvc_list_boss'"]);
		self::assertCount(1, $page->items);
		self::assertSame(1, $page->total);
		self::assertSame('42', (string) ($page->items[0]->row['hooked'] ?? ''));

		// l.524-526: the employee list without salaries->readall is limited to the hierarchy
		self::assertSame(1, $this->search(['search_login' => 'mvc_list_'], [], [$boss])->total);
	}

	public function testCategoryWithoutTag(): void
	{
		$id = $this->insertUser('mvc_list_tag', 1);

		// list.php l.498-499: -2 is "without tag"
		self::assertSame(1, $this->search(['search_login' => 'mvc_list_tag', 'search_categ' => '-2'])->total);
		self::assertSame(0, $this->search(['search_login' => 'mvc_list_tag', 'search_categ' => '999999'])->total);
		self::assertSame($id, $this->search(['search_login' => 'mvc_list_tag'])->items[0]->user->id);
	}

	/**
	 * @param array<string, string>                              $query
	 * @param array{select?: string, from?: string, where?: string} $fragments
	 * @param ?list<int>                                         $childIds
	 */
	private function search(array $query, array $fragments = [], ?array $childIds = null): \Dolibarr\Module\User\Repository\UserPage
	{
		$r = GlobalsRequest::create('GET', '/user', $query);
		$s = UserSearch::fromRequest($r, new LegacyConfig($this->ctx), false, 20);

		return (new UserRepository($this->connection, (int) $this->ctx->conf->entity))->search($s, $childIds, null, $fragments);
	}

	private function insertUser(string $login, int $status, string $lastname = 'List', ?int $supervisor = null): int
	{
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX."user (login, lastname, entity, statut, datec, fk_user) VALUES ('".$login."', '".$lastname."', 1, ".$status.', NOW(), '.($supervisor ?? 'NULL').')');

		return $this->connection->lastInsertId(MAIN_DB_PREFIX.'user');
	}
}
