<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Legacy\LegacyEntityScope;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Core\Orm\NaturalSearch;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Core\Orm\Query;
use Dolibarr\Core\Orm\Snapshots;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmSearchRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NaturalSearch finds the same rows as natural_search() and dol_natural_search_phone() (run through the DoliDB of
 * the engine, $db->query(), which converts their SQL for it), on a table created in the dialect of the connection.
 * The legacy functions escape with the global $db and read the global $langs: both are swapped for the
 * connection's DoliDB (and the language tested) while they build their SQL. A subclass gives the connection:
 * MariaDB (Integration) and PostgreSQL (Pgsql).
 */
abstract class NaturalSearchEquivalenceTestCase extends TestCase
{
	private const TABLE = 'mvc_orm_search';

	/** label, code, qty, amount, phone, tags (multiselect) */
	private const ROWS = [
		['Élise Dupont', 'FR', 10, 10.5, '01 23 45 67 89', 'a,b'],
		['elise martin', 'fr', 5, 5.0, '01.23.45.67.89', 'b'],
		["O'Brien", 'IE', 1, -2.5, '+33 1 23 45 67 89', 'c,a,d'],
		["l'école", 'FR', 3, 1234.5, '0123456789', 'a'],
		['100% coton', 'US', 0, 0.0, null, 'ab'],
		['snake_case', 'US', 12, 12.75, '06 12 34 56 78', null],
		['snakeXcase', 'GB', 2, 2.0, '+44 20 7946 0958', 'b,c'],
		[null, null, null, null, null, null],
		['', '', 4, 4.4, '', ''],
		['Alpha beta', 'DE', 7, 7.0, '0612345678', 'd'],
		['alpha', 'de', 15, 15.5, '+33612345678', 'a,d'],
		['Beta Gamma', 'ES', 20, -0.5, '06.12.34.56.78', 'c'],
		['a b', 'IT', 1, 1.0, null, 'a,b,c'],
		['Zoë', 'NL', 100, 100.25, '+31 20 123 4567', 'e'],
		['Ünïcode ß', 'DE', 9, 9.99, '(01) 23 45', 'b,a'],
		['x', 'x', 6, 6.0, null, 'x'],
		['y', 'y', 8, 8.0, null, 'y,x'],
		["a' OR '1'='1", 'XX', 11, 11.0, null, null],
		['back\\slash', 'BS', 13, 13.0, null, null],
		['50%', 'P', 50, 50.0, null, null],
		['under_score', 'U_S', 21, 21.0, null, 'a_b'],
		['Dupont & Fils', 'FR', 3, 3.0, '01 98 76 54 32', 'a'],
		['Élysée', 'É', 30, 30.5, '+33 6 12 34 56 78', 'b'],
	];

	private Connection $connection;

	private Orm $orm;

	abstract protected function connection(): Connection;

	protected function setUp(): void
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/phone.lib.php';
		$this->langs()->load('main');
		$this->connection = $this->connection();
		$this->dropTable();
		$this->connection->execute(
			$this->connection->dialect() === 'pgsql'
				? 'CREATE TABLE @'.self::TABLE.' (rowid SERIAL PRIMARY KEY, label varchar(128) NULL, code varchar(32) NULL, qty integer NULL,'
					.' amount numeric(24,8) NULL, phone varchar(32) NULL, tags varchar(64) NULL)'
				: 'CREATE TABLE @'.self::TABLE.' (rowid integer AUTO_INCREMENT PRIMARY KEY, label varchar(128) NULL, code varchar(32) NULL, qty integer NULL,'
					.' amount double(24,8) NULL, phone varchar(32) NULL, tags varchar(64) NULL) ENGINE=InnoDB'
		);
		foreach (self::ROWS as [$label, $code, $qty, $amount, $phone, $tags]) {
			$this->connection->execute(
				'INSERT INTO @'.self::TABLE.' (label, code, qty, amount, phone, tags) VALUES (:label, :code, :qty, :amount, :phone, :tags)',
				['label' => $label, 'code' => $code, 'qty' => $qty, 'amount' => $amount, 'phone' => $phone, 'tags' => $tags]
			);
		}
		$hydrator = new Hydrator();
		$this->orm = new Orm($this->connection, new MappingRegistry(), new LegacyEntityScope(), $hydrator, new Snapshots($hydrator));
	}

	protected function tearDown(): void
	{
		$this->dropTable();
	}

	/**
	 * @return iterable<string, array{list<string>, string, int, bool}> Columns, value, mode, whether the case may
	 *                                                          find no row or every row
	 */
	public static function cases(): iterable
	{
		$label = ['t.label'];
		yield 'mode 0: word' => [$label, 'alpha', 0, false];
		yield 'mode 0: a|b' => [$label, 'a|b', 0, false];
		yield 'mode 0: words OR' => [$label, 'alpha|gamma', 0, false];
		yield 'mode 0: spaces around |' => [$label, '  alpha  |  gamma ', 0, false];
		yield 'mode 0: !a' => [$label, '!a', 0, false];
		yield 'mode 0: !alpha|beta (AND)' => [$label, '!alpha|beta', 0, false];
		yield 'mode 0: "a b"' => [$label, '"a b"', 0, false];
		yield "mode 0: 'a b'" => [$label, "'a b'", 0, false];
		yield 'mode 0: words AND' => [$label, 'alpha beta', 0, false];
		yield 'mode 0: a*b' => [$label, 'a*b', 0, false];
		yield 'mode 0: snake*case' => [$label, 'snake*case', 0, false];
		yield 'mode 0: % literal' => [$label, '%', 0, false];
		yield 'mode 0: 50%' => [$label, '50%', 0, false];
		yield 'mode 0: _' => [$label, '_', 0, false];
		yield 'mode 0: snake_case (_ wildcard)' => [$label, 'snake_case', 0, false];
		yield 'mode 0: hostile value' => [$label, "a' OR '1'='1", 0, false];
		yield 'mode 0: hostile quoted' => [$label, "\"a' OR '1'='1\"", 0, false];
		yield 'mode 0: apostrophe' => [$label, "O'Brien", 0, false];
		yield 'mode 0: apostrophe and accent' => [$label, "l'école", 0, false];
		yield 'mode 0: accent' => [$label, 'élise', 0, false];
		yield 'mode 0: upper case' => [$label, 'ELISE', 0, false];
		yield 'mode 0: ^start' => [$label, '^alpha', 0, false];
		yield 'mode 0: end$' => [$label, 'case$', 0, false];
		yield 'mode 0: ^exact$' => [$label, '^alpha$', 0, false];
		yield 'mode 0: ^$ empty or null' => [$label, '^$', 0, false];
		yield 'mode 0: alpha|^$ (joined with AND)' => [$label, 'alpha|^$', 0, true];
		yield 'mode 0: !^a (^ literal: every row)' => [$label, '!^a', 0, true];
		yield 'mode 0: ^!a' => [$label, '^!a', 0, true];
		yield 'mode 0: 0' => [$label, '0', 0, false];
		yield 'mode 0: backslash' => [$label, 'back\\slash', 0, true];
		yield 'mode 0: escaped backslash' => [$label, 'back\\\\slash', 0, false];
		yield 'mode 0: a|b c precedence' => [$label, 'a|b c', 0, false];
		yield 'mode 0: unicode' => [$label, 'Zoë', 0, false];
		yield 'mode 0: two columns' => [['t.label', 't.code'], 'fr', 0, false];
		yield 'mode 0: two columns OR' => [['t.label', 't.code'], 'fr|de', 0, false];
		yield 'mode 0: two columns !' => [['t.label', 't.code'], '!fr', 0, false];
		yield 'mode 0: two columns AND' => [['t.label', 't.code'], 'alpha de', 0, false];
		yield 'mode 0: two columns a|b c' => [['t.label', 't.code'], 'a|e x', 0, false];
		yield 'mode 0: rowid' => [['t.rowid'], '3', 0, false];
		yield 'mode 0: rowid OR' => [['t.rowid'], '3|5', 0, false];
		yield 'mode 0: rowid not a number' => [['t.rowid'], 'abc', 0, true];
		yield 'mode 1: >=10' => [['t.qty'], '>=10', 1, false];
		yield 'mode 1: >= 10' => [['t.qty'], '>= 10', 1, false];
		yield 'mode 1: <5 >1' => [['t.qty'], '<5 >1', 1, false];
		yield 'mode 1: !=3' => [['t.qty'], '!=3', 1, false];
		yield 'mode 1: <>3' => [['t.qty'], '<>3', 1, false];
		yield 'mode 1: =' => [['t.qty'], '5', 1, false];
		yield 'mode 1: <=' => [['t.qty'], '<=2', 1, false];
		yield 'mode 1: |' => [['t.qty'], '1|>=20', 1, false];
		yield 'mode 1: < 5' => [['t.qty'], '< 5', 1, false];
		yield 'mode 1: not a number' => [['t.qty'], 'abc', 1, true];
		yield 'mode 1: hostile' => [['t.qty'], '1) OR (1', 1, true];
		yield 'mode 1: decimal' => [['t.amount'], '>=10.5', 1, false];
		yield 'mode 1: negative' => [['t.amount'], '< -1', 1, false];
		yield 'mode 1: thousand separator' => [['t.amount'], '>1,000', 1, false];
		yield 'mode 1: decimal equality' => [['t.amount'], '12.75', 1, false];
		yield 'mode 1: two columns' => [['t.qty', 't.amount'], '>12', 1, false];
		yield 'mode 1: two columns two criteria' => [['t.qty', 't.amount'], '>1 <=10', 1, false];
		yield 'mode 2: 1,2,3' => [['t.rowid'], '1,2,3', 2, false];
		yield 'mode 2: one id' => [['t.rowid'], '3', 2, false];
		yield 'mode 2: spaces are AND' => [['t.rowid'], '1 2', 2, true];
		yield 'mode 2: hostile' => [['t.rowid'], '1) OR (1=1', 2, true];
		yield 'mode 2: on qty' => [['t.qty'], '1,3,-5', 2, false];
		yield 'mode -2: NOT IN or NULL' => [['t.qty'], '1,3', -2, false];
		yield 'mode 3: x,y' => [['t.code'], 'x,y', 3, false];
		yield 'mode 3: FR,DE' => [['t.code'], 'FR,DE', 3, false];
		yield 'mode 3: !FR' => [['t.code'], '!FR', 3, false];
		yield 'mode 3: !=FR,!DE' => [['t.code'], '!=FR,!DE', 3, false];
		yield 'mode 3: IN and NOT IN' => [['t.code'], 'FR,ES,!fr', 3, true];
		yield 'mode 3: sanitized' => [['t.code'], "x%,y',U_S", 3, false];
		yield 'mode 3: hostile' => [['t.code'], "x') OR ('1'='1", 3, true];
		yield 'mode 3: spaces kept' => [['t.label'], 'a b , x', 3, false];
		yield 'mode 3: accent' => [['t.code'], 'É', 3, false];
		yield 'mode 3: two columns' => [['t.label', 't.code'], 'x,fr', 3, false];
		yield 'mode -3: NOT IN or NULL' => [['t.code'], 'FR,!US', -3, false];
		yield 'mode 4: a' => [['t.tags'], 'a', 4, false];
		yield 'mode 4: a,c' => [['t.tags'], 'a,c', 4, false];
		yield 'mode 4: x' => [['t.tags'], 'x', 4, false];
		yield 'mode 4: 0 skipped' => [['t.tags'], '0,e', 4, false];
		yield 'mode 4: _ wildcard' => [['t.tags'], 'a_b', 4, false];
		yield 'mode 4: two criteria' => [['t.tags'], 'a b', 4, false];
	}

	/**
	 * @param list<string> $columns
	 */
	#[DataProvider('cases')]
	public function testSameRowsAsNaturalSearch(array $columns, string $value, int $mode, bool $trivial): void
	{
		$this->assertSameRows(
			fn (): string => natural_search($columns, $value, $mode),
			fn (Query $query): Query => $this->naturalSearch()->apply($query, $columns, $value, $mode),
			$trivial
		);
	}

	public function testLegacySqlIsBuiltWithTheConnectionDoliDb(): void
	{
		$legacy = $this->connection->legacy();
		$savedDb = $GLOBALS['db'];
		$GLOBALS['db'] = $legacy;
		try {
			$where = natural_search('t.label', "O'Brien");
		} finally {
			$GLOBALS['db'] = $savedDb;
		}

		self::assertSame(" AND (t.label LIKE '%".$legacy->escape("O'Brien")."%')", $where);
		self::assertSame($this->connection->dialect() === 'pgsql' ? "O''Brien" : "O\\'Brien", $legacy->escape("O'Brien"));
	}

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function phoneCases(): iterable
	{
		yield 'digits' => ['0123456789', false];
		yield 'spaced' => ['01 23 45 67 89', false];
		yield 'dotted' => ['01.23.45.67.89', false];
		yield 'international, stored with spaces' => ['+33 1 23 45 67 89', true];
		yield 'international' => ['+33 6 12 34 56 78', false];
		yield 'trunk prefix removed' => ['06.12', false];
		yield 'without trunk prefix' => ['612345678', false];
		yield 'brackets' => ['(01) 23', false];
		yield 'only separators' => ['()', true];
		yield 'alternatives' => ['0198|+44', false];
	}

	#[DataProvider('phoneCases')]
	public function testSameRowsAsNaturalSearchPhone(string $value, bool $trivial): void
	{
		$prefixes = array_map(
			static fn (array $row): string => (string) $row['trunk_prefix'],
			$this->connection->fetchAll("SELECT DISTINCT trunk_prefix FROM @c_country WHERE trunk_prefix IS NOT NULL AND trunk_prefix <> ''")
		);
		self::assertNotSame([], $prefixes, 'The install has trunk prefixes');
		$search = new NaturalSearch($this->langs()->trans('SeparatorDecimal'), $this->langs()->transnoentitiesnoconv('SeparatorThousand'), $prefixes);

		$this->assertSameRows(
			fn (): string => dol_natural_search_phone($this->connection->legacy(), 't.phone', $value),
			static fn (Query $query): Query => $search->applyPhone($query, 't.phone', $value),
			$trivial
		);
	}

	/**
	 * @return iterable<string, array{list<string>, string, int}>
	 */
	public static function frenchCases(): iterable
	{
		yield 'decimal comma' => [['t.amount'], '>= 10,5', 1];
		yield 'thousands' => [['t.amount'], '>1000,25', 1];
		yield 'dot is accepted too' => [['t.amount'], '<=9.99', 1];
		yield 'negative' => [['t.amount'], '<-0,5|>100', 1];
		yield 'text unchanged' => [['t.label'], 'élise|alpha', 0];
	}

	/**
	 * @param list<string> $columns
	 */
	#[DataProvider('frenchCases')]
	public function testSameRowsInFrench(array $columns, string $value, int $mode): void
	{
		$saved = $GLOBALS['langs'];
		$french = new \Translate('', $GLOBALS['conf']);
		$french->setDefaultLang('fr_FR');
		$french->load('main');
		self::assertSame(',', $french->trans('SeparatorDecimal'));
		$GLOBALS['langs'] = $french;
		try {
			$this->assertSameRows(
				fn (): string => natural_search($columns, $value, $mode),
				fn (Query $query): Query => $this->naturalSearch()->apply($query, $columns, $value, $mode),
				false
			);
		} finally {
			$GLOBALS['langs'] = $saved;
		}
	}

	/**
	 * @param \Closure(): string                    $legacyWhere The ' AND (…)' of the legacy function, built with the
	 *                                              global $db swapped
	 * @param \Closure(Query<object>): Query<object> $search
	 */
	private function assertSameRows(\Closure $legacyWhere, \Closure $search, bool $trivial): void
	{
		$legacy = $this->connection->legacy();
		$savedDb = $GLOBALS['db'];
		$GLOBALS['db'] = $legacy;
		try {
			$where = $legacyWhere();
		} finally {
			$GLOBALS['db'] = $savedDb;
		}
		$sql = 'SELECT t.rowid FROM '.$legacy->prefix().self::TABLE.' AS t WHERE 1 = 1'.$where;
		$result = $legacy->query($sql);
		self::assertNotFalse($result, 'Legacy SQL failed: '.$sql.' '.$legacy->lasterror());
		$legacyIds = [];
		while ($row = $legacy->fetch_object($result)) {
			$legacyIds[] = (int) $row->rowid;
		}
		sort($legacyIds);

		$query = $search($this->orm->query(OrmSearchRow::class)->select('id'));
		$boundIds = array_map(static fn (array $row): int => (int) $row['rowid'], $query->rows());
		sort($boundIds);

		$compiled = $query->compile();
		self::assertSame($legacyIds, $boundIds, 'Legacy: '.$sql."\nBound: ".$compiled->sql.' '.json_encode($compiled->params, JSON_UNESCAPED_UNICODE));
		if (!$trivial) {
			self::assertNotSame([], $legacyIds, 'The case should find rows: '.$sql);
			self::assertLessThan(count(self::ROWS), count($legacyIds), 'A condition applies: '.$sql);
		}
	}

	private function naturalSearch(): NaturalSearch
	{
		return new NaturalSearch($this->langs()->trans('SeparatorDecimal'), $this->langs()->transnoentitiesnoconv('SeparatorThousand'));
	}

	private function langs(): \Translate
	{
		$langs = $GLOBALS['langs'];
		self::assertInstanceOf(\Translate::class, $langs);

		return $langs;
	}

	private function dropTable(): void
	{
		$this->connection->execute('DROP TABLE IF EXISTS @'.self::TABLE);
	}
}
