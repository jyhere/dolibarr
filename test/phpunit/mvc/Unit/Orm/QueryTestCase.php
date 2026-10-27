<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm;

use Dolibarr\Core\Orm\CompiledQuery;
use Dolibarr\Core\Orm\Grammar;
use Dolibarr\Core\Orm\MappingReader;
use Dolibarr\Core\Orm\Query;
use Dolibarr\Tests\Unit\Orm\Fixture\Article;
use Dolibarr\Tests\Unit\Orm\Fixture\ArticleStatus;
use Dolibarr\Tests\Unit\Orm\Fixture\Author;
use Dolibarr\Tests\Unit\Orm\Fixture\FakeEntityScope;
use PHPUnit\Framework\TestCase;

/**
 * The SQL and parameters a Query compiles to, the same on every dialect; the subclasses add what differs.
 */
abstract class QueryTestCase extends TestCase
{
	protected FakeEntityScope $entityScope;

	abstract protected function grammar(): Grammar;

	protected function setUp(): void
	{
		$this->entityScope = new FakeEntityScope();
	}

	public function testSimpleWhere(): void
	{
		$compiled = $this->authors()->where('name', 'Dupont')->compile();

		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t WHERE t.name = :p1', ['p1' => 'Dupont'], $compiled);
	}

	public function testDefaultSelectListsMappedColumns(): void
	{
		$compiled = $this->articles()->withoutEntityScope()->compile();

		$this->assertCompiled(
			'SELECT t.rowid, t.title, t.statut, t.price, t.featured, t.view_count, t.fk_author, t.published_on, t.datec, t.metadata, t.entity, t.summary FROM @article AS t',
			[],
			$compiled
		);
	}

	public function testOperatorsAndInvalidOperatorThrows(): void
	{
		$compiled = $this->articles()->withoutEntityScope()->select('id')
			->where('viewCount', '>=', '10')
			->where('price', '<', 2.5)
			->where('title', 'NOT LIKE', 'a%')
			->where('title', '!=', 'x')
			->where('summary', '<>', 'y')
			->where('status', ArticleStatus::Published)
			->where('featured', '>', false)
			->where('publishedOn', '<=', new \DateTimeImmutable('2026-03-29 14:00:00'))
			->compile();

		$this->assertCompiled(
			'SELECT t.rowid FROM @article AS t WHERE t.view_count >= :p1 AND t.price < :p2 AND t.title NOT LIKE :p3 AND t.title != :p4'
			.' AND t.summary <> :p5 AND t.statut = :p6 AND t.featured > :p7 AND t.published_on <= :p8',
			['p1' => 10, 'p2' => 2.5, 'p3' => 'a%', 'p4' => 'x', 'p5' => 'y', 'p6' => 1, 'p7' => 0, 'p8' => '2026-03-29'],
			$compiled
		);

		foreach (['= 1 OR 1 = 1', 'IN', 'is', '==', 'like; DROP'] as $operator) {
			try {
				$this->authors()->where('name', $operator, 'x');
				self::fail('Operator accepted: '.$operator);
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString('operator', $e->getMessage());
			}
		}
	}

	public function testTwoArgumentsAreAlwaysAnEquality(): void
	{
		$compiled = $this->authors()->where('name', 'like')->compile();

		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t WHERE t.name = :p1', ['p1' => 'like'], $compiled);
	}

	public function testHostileValuesAreBoundNeverConcatenated(): void
	{
		$compiled = $this->articles()->withoutEntityScope()->select('id')
			->where('title', "a' OR 1=1 --")
			->where('viewCount', '1) OR (1')
			->compile();

		$this->assertCompiled('SELECT t.rowid FROM @article AS t WHERE t.title = :p1 AND t.view_count = :p2', ['p1' => "a' OR 1=1 --", 'p2' => 0], $compiled);
	}

	public function testNestedGroups(): void
	{
		$compiled = $this->authors()
			->where('name', 'a')
			->where(static function (Query $group): void {
				$group->where('name', 'b')->orWhere('id', '3');
			})
			->orWhere('name', 'c')
			->orWhere(static function (Query $group): void {
				$group->where('name', 'd')->where(static function (Query $inner): void {
					$inner->whereNull('name')->orWhere('rowid', '>', 5);
				});
			})
			->where(static function (Query $empty): void {
			})
			->compile();

		$this->assertCompiled(
			'SELECT t.rowid, t.name FROM @author AS t WHERE t.name = :p1 AND (t.name = :p2 OR t.rowid = :p3) OR t.name = :p4'
			.' OR (t.name = :p5 AND (t.name IS NULL OR t.rowid > :p6))',
			['p1' => 'a', 'p2' => 'b', 'p3' => 3, 'p4' => 'c', 'p5' => 'd', 'p6' => 5],
			$compiled
		);
	}

	public function testWhereInEmptyIsFalse(): void
	{
		$compiled = $this->authors()->whereIn('id', [])->whereNotIn('name', [])->compile();
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t WHERE 1 = 0 AND 1 = 1', [], $compiled);

		$compiled = $this->authors()->whereIn('id', ['3', 7])->whereNotIn('name', ['x'])->compile();
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t WHERE t.rowid IN (:p1) AND t.name NOT IN (:p2)', ['p1' => [3, 7], 'p2' => ['x']], $compiled);
	}

	public function testWhereInSubquery(): void
	{
		$featured = $this->articles('a')->select('authorId')->where('featured', true);

		$compiled = $this->authors()->where('name', 'x')->whereIn('id', $featured)->whereNotIn('id', $this->articles('b')->withoutEntityScope()->select('authorId'))->compile();

		$this->assertCompiled(
			'SELECT t.rowid, t.name FROM @author AS t WHERE t.name = :p1'
			.' AND t.rowid IN (SELECT a.fk_author FROM @article AS a WHERE a.entity IN (:entities) AND a.featured = :p2)'
			.' AND t.rowid NOT IN (SELECT b.fk_author FROM @article AS b)',
			['p1' => 'x', 'entities' => [1, 2], 'p2' => 1],
			$compiled
		);
		self::assertSame(['blog_article'], $this->entityScope->askedElements);
	}

	public function testNullChecks(): void
	{
		$compiled = $this->articles()->withoutEntityScope()->select('id')
			->whereNull('summary')
			->whereNotNull('authorId')
			->where('price', null)
			->where('title', '!=', null)
			->whereNull('t.datec')
			->compile();

		$this->assertCompiled(
			'SELECT t.rowid FROM @article AS t WHERE t.summary IS NULL AND t.fk_author IS NOT NULL AND t.price IS NULL AND t.title IS NOT NULL AND t.datec IS NULL',
			[],
			$compiled
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->articles()->where('price', '<', null);
	}

	public function testExists(): void
	{
		$articles = $this->articles('a')->withoutEntityScope()->select('id')->whereRaw('a.fk_author = t.rowid')->where('a.title', 'x');

		$compiled = $this->authors()->whereExists($articles)->compile();

		$this->assertCompiled(
			'SELECT t.rowid, t.name FROM @author AS t WHERE EXISTS (SELECT a.rowid FROM @article AS a WHERE (a.fk_author = t.rowid) AND a.title = :p1)',
			['p1' => 'x'],
			$compiled
		);
	}

	public function testWhereRawRenamesItsParameters(): void
	{
		$compiled = $this->articles()->withoutEntityScope()->select('id')
			->where('title', 'x')
			->whereRaw("t.view_count > :min AND t.title <> ':min' AND t.price::text = :min AND t.fk_author IN (:authors)", ['min' => 3, 'authors' => [4, 5]])
			->compile();

		$this->assertCompiled(
			"SELECT t.rowid FROM @article AS t WHERE t.title = :p1 AND (t.view_count > :p2 AND t.title <> ':min' AND t.price::text = :p2 AND t.fk_author IN (:p3))",
			['p1' => 'x', 'p2' => 3, 'p3' => [4, 5]],
			$compiled
		);

		foreach ([['t.title = :missing', []], ['t.title = :title', ['title' => 'x', 'extra' => 1]]] as [$sql, $params]) {
			try {
				$this->authors()->whereRaw($sql, $params);
				self::fail('Raw SQL accepted: '.$sql);
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString(':', $e->getMessage());
			}
		}
	}

	public function testJoinsAndAliasedColumns(): void
	{
		$compiled = $this->articles()->withoutEntityScope()
			->join('author', 'a', 'a.rowid', 't.fk_author')
			->leftJoin('c_country', 'co', 'co.rowid', 'a.fk_country')
			->select('t.title', 'a.name')
			->where('a.name', 'Dup')
			->where('co.code', 'like', 'F%')
			->orderBy('a.name', 'desc')
			->groupBy('t.title', 'a.name')
			->compile();

		$this->assertCompiled(
			'SELECT t.title, a.name FROM @article AS t INNER JOIN @author AS a ON a.rowid = t.fk_author'
			.' LEFT JOIN @c_country AS co ON co.rowid = a.fk_country WHERE a.name = :p1 AND co.code LIKE :p2'
			.' GROUP BY t.title, a.name ORDER BY a.name DESC',
			['p1' => 'Dup', 'p2' => 'F%'],
			$compiled
		);

		$invalidJoins = [
			['author; DROP', 'a', 'a.rowid', 't.fk_author'],
			['author', 't', 't.rowid', 't.fk_author'],
			['author', 'a b', 'a.rowid', 't.fk_author'],
			['author', 'a', 'a.rowid', 't.nope'],
			['author', 'a', 'a.rowid = 1 OR 1', 't.fk_author'],
		];
		foreach ($invalidJoins as [$table, $alias, $leftColumn, $rightColumn]) {
			try {
				$this->articles()->join($table, $alias, $leftColumn, $rightColumn);
				self::fail('Join accepted: '.$table.' '.$alias.' '.$leftColumn.' '.$rightColumn);
			} catch (\InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}

		$this->expectException(\InvalidArgumentException::class);
		$this->articles()->join('author', 'a', 'a.rowid', 't.fk_author')->join('tag', 'a', 'a.rowid', 't.rowid');
	}

	public function testUnknownColumnThrows(): void
	{
		$attempts = [
			'where unmapped' => static fn (Query $query): Query => $query->where('pass_crypted', 'x'),
			'where aliased unmapped' => static fn (Query $query): Query => $query->where('t.nope', 1),
			'where unknown alias' => static fn (Query $query): Query => $query->where('zz.name', 1),
			'where relation' => static fn (Query $query): Query => $query->where('author', 1),
			'where expression' => static fn (Query $query): Query => $query->where('(SELECT 1)', 1),
			'orderBy' => static fn (Query $query): Query => $query->orderBy('nope'),
			'select' => static fn (Query $query): Query => $query->select('t.*'),
			'groupBy' => static fn (Query $query): Query => $query->groupBy('nope'),
			'whereIn' => static fn (Query $query): Query => $query->whereIn('nope', []),
			'whereNull' => static fn (Query $query): Query => $query->whereNull('nope'),
			'with' => static fn (Query $query): Query => $query->with('nope'),
		];
		foreach ($attempts as $label => $attempt) {
			try {
				$attempt($this->articles());
				self::fail('Accepted: '.$label);
			} catch (\InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testIsSortableRejectsSecretAndExpressions(): void
	{
		$query = $this->articles()
			->join('author', 'a', 'a.rowid', 't.fk_author', Author::class)
			->leftJoin('c_country', 'co', 'co.rowid', 't.fk_author', ['label'])
			->leftJoin('societe', 's', 's.rowid', 't.fk_author')
			->leftJoin('article', 'parent', 'parent.rowid', 't.fk_author');

		$refused = [
			'pass_crypted', 't.pass_crypted', '(SELECT 1)', 'u.login; DROP', 't.title DESC', 'title, (SELECT 1)', 'author', '',
			'zz.name', 'a.name desc', 'parent.pass_crypted', 'T.title', "title\n", "t.title\n", 'a.nonexistent', 's.nom',
			'co.code', 'co.pass_crypted', 's.api_key', 'title AS x',
		];
		foreach ($refused as $column) {
			self::assertFalse($query->isSortable($column), $column);
		}
		foreach (['title', 't.title', 'viewCount', 't.view_count', 'a.name', 'a.rowid', 'co.label', 'parent.title', 'parent.viewCount'] as $column) {
			self::assertTrue($query->isSortable($column), $column);
		}
	}

	public function testSecretColumnsRefusedOnAnyAlias(): void
	{
		$attempts = [
			'select' => static fn (Query $query): Query => $query->select('u.pass_crypted'),
			'select as' => static fn (Query $query): Query => $query->select('u.api_key AS key'),
			'where' => static fn (Query $query): Query => $query->where('u.Pass_Crypted', 'x'),
			'whereNull' => static fn (Query $query): Query => $query->whereNull('u.pass_temp'),
			'orderBy' => static fn (Query $query): Query => $query->orderBy('u.ldap_sid'),
			'groupBy' => static fn (Query $query): Query => $query->groupBy('u.openid'),
			'join on' => static fn (Query $query): Query => $query->leftJoin('user', 'u2', 'u2.pass', 't.title'),
		];
		foreach ($attempts as $label => $attempt) {
			try {
				$attempt($this->articles()->join('user', 'u', 'u.rowid', 't.fk_author'));
				self::fail('Secret column accepted: '.$label);
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString('ecret', $e->getMessage(), $label);
			}
		}
		self::assertFalse($this->articles()->join('user', 'u', 'u.rowid', 't.fk_author')->isSortable('u.pass_crypted'));

		$compiled = $this->articles()->withoutEntityScope()->join('user', 'u', 'u.rowid', 't.fk_author')->select('u.login')->where('u.statut', 1)->compile();
		$this->assertCompiled('SELECT u.login FROM @article AS t INNER JOIN @user AS u ON u.rowid = t.fk_author WHERE u.statut = :p1', ['p1' => 1], $compiled);
	}

	public function testJoinDeclarations(): void
	{
		$compiled = $this->articles()->withoutEntityScope()
			->join('author', 'a', 'a.id', 't.authorId', Author::class)
			->select('a.name')
			->where('a.id', '7')
			->compile();
		$this->assertCompiled('SELECT a.name FROM @article AS t INNER JOIN @author AS a ON a.rowid = t.fk_author WHERE a.rowid = :p1', ['p1' => 7], $compiled);

		$invalid = [
			'unmapped column of a mapped join' => static fn (Query $query): Query => $query->join('author', 'a', 'a.rowid', 't.fk_author', Author::class)->where('a.nope', 1),
			'unknown class' => static fn (Query $query): Query => $query->join('author', 'a', 'a.rowid', 't.fk_author', 'No\\Such\\Entity'),
			'not an entity' => static fn (Query $query): Query => $query->join('author', 'a', 'a.rowid', 't.fk_author', \stdClass::class),
			'secret declared' => static fn (Query $query): Query => $query->join('user', 'u', 'u.rowid', 't.fk_author', ['login', 'pass_crypted']),
			'invalid declared' => static fn (Query $query): Query => $query->join('user', 'u', 'u.rowid', 't.fk_author', ['login; DROP']),
		];
		foreach ($invalid as $label => $attempt) {
			try {
				$attempt($this->articles());
				self::fail('Accepted: '.$label);
			} catch (\InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testSelectAliasesAndRawExpressions(): void
	{
		$compiled = $this->articles()->withoutEntityScope()
			->join('author', 'a', 'a.rowid', 't.fk_author')
			->select('t.title AS heading', 'a.name as author_name', 'viewCount')
			->selectRaw("CASE WHEN t.summary IS NOT NULL AND t.summary <> '' THEN 1 ELSE 0 END", 'has_summary')
			->selectRaw('CASE WHEN t.view_count > :threshold THEN 1 ELSE 0 END', 'popular', ['threshold' => 100])
			->where('title', 'x')
			->compile();

		$this->assertCompiled(
			'SELECT t.title AS heading, a.name AS author_name, t.view_count,'
			." (CASE WHEN t.summary IS NOT NULL AND t.summary <> '' THEN 1 ELSE 0 END) AS has_summary,"
			.' (CASE WHEN t.view_count > :p1 THEN 1 ELSE 0 END) AS popular'
			.' FROM @article AS t INNER JOIN @author AS a ON a.rowid = t.fk_author WHERE t.title = :p2',
			['p1' => 100, 'p2' => 'x'],
			$compiled
		);

		$distinct = $this->articles()->withoutEntityScope()->distinct()->selectRaw('t.view_count > :threshold', 'popular', ['threshold' => 5])->where('title', 'x');
		$this->assertCompiled(
			'SELECT COUNT(*) AS total FROM (SELECT DISTINCT (t.view_count > :p1) AS popular FROM @article AS t WHERE t.title = :p2) AS counted',
			['p1' => 5, 'p2' => 'x'],
			$distinct->compileCount()
		);

		$invalid = [
			'alias with spaces' => static fn (Query $query): Query => $query->select('title AS x y'),
			'alias expression' => static fn (Query $query): Query => $query->select('title AS (SELECT 1)'),
			'alias digit' => static fn (Query $query): Query => $query->select('title AS 1x'),
			'alias newline' => static fn (Query $query): Query => $query->select("title AS x\n"),
			'column newline' => static fn (Query $query): Query => $query->select("title\n"),
			'aliased unknown column' => static fn (Query $query): Query => $query->select('nope AS x'),
			'raw alias' => static fn (Query $query): Query => $query->selectRaw('1', 'x; DROP'),
			'raw alias newline' => static fn (Query $query): Query => $query->selectRaw('1', "x\n"),
			'raw missing parameter' => static fn (Query $query): Query => $query->selectRaw('t.view_count > :min', 'popular'),
			'raw unused parameter' => static fn (Query $query): Query => $query->selectRaw('1', 'one', ['min' => 1]),
		];
		foreach ($invalid as $label => $attempt) {
			try {
				$attempt($this->articles());
				self::fail('Accepted: '.$label);
			} catch (\InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testWhereNotExists(): void
	{
		$articles = $this->articles('a')->withoutEntityScope()->select('id')->whereRaw('a.fk_author = t.rowid')->where('a.title', 'x');

		$compiled = $this->authors()->where('name', 'n')->whereNotExists($articles)->compile();

		$this->assertCompiled(
			'SELECT t.rowid, t.name FROM @author AS t WHERE t.name = :p1 AND NOT EXISTS (SELECT a.rowid FROM @article AS a WHERE (a.fk_author = t.rowid) AND a.title = :p2)',
			['p1' => 'n', 'p2' => 'x'],
			$compiled
		);
	}

	public function testGroupRefusesNonConditionCalls(): void
	{
		$calls = [
			'legacyFragment' => static fn (Query $group): Query => $group->legacyFragment('1 = 1'),
			'join' => static fn (Query $group): Query => $group->join('tag', 'tg', 'tg.rowid', 't.rowid'),
			'leftJoin' => static fn (Query $group): Query => $group->leftJoin('tag', 'tg', 'tg.rowid', 't.rowid'),
			'orderBy' => static fn (Query $group): Query => $group->orderBy('name'),
			'groupBy' => static fn (Query $group): Query => $group->groupBy('name'),
			'limit' => static fn (Query $group): Query => $group->limit(1),
			'offset' => static fn (Query $group): Query => $group->offset(1),
			'distinct' => static fn (Query $group): Query => $group->distinct(),
			'caseInsensitive' => static fn (Query $group): Query => $group->caseInsensitive(),
			'withoutEntityScope' => static fn (Query $group): Query => $group->withoutEntityScope(),
			'select' => static fn (Query $group): Query => $group->select('name'),
			'selectRaw' => static fn (Query $group): Query => $group->selectRaw('1', 'one'),
			'with' => static fn (Query $group): Query => $group->with(),
			'compile' => static fn (Query $group): mixed => $group->compile(),
		];
		foreach ($calls as $label => $call) {
			try {
				$this->authors()->where(static function (Query $group) use ($call): void {
					$call($group->where('name', 'x'));
				});
				self::fail('Accepted in a group: '.$label);
			} catch (\LogicException $e) {
				self::assertStringContainsString('group', $e->getMessage(), $label);
			}
		}

		$compiled = $this->authors()->where(static function (Query $group): void {
			$group->whereIn('id', [1])->orWhere('name', 'x')->whereNull('name')->whereNotNull('name')->whereRaw('1 = 1')
				->whereExists(new Query((new MappingReader())->read(Author::class), new \Dolibarr\Core\Orm\MysqlGrammar(), null, null, 'a'));
		})->compile();
		self::assertStringContainsString('WHERE (t.rowid IN (:p1) OR t.name = :p2 AND t.name IS NULL AND t.name IS NOT NULL AND (1 = 1) AND EXISTS (', $compiled->sql);
	}

	public function testIdentifiersRefuseTrailingNewline(): void
	{
		$invalid = [
			'join table' => fn (): Query => $this->articles()->join("author\n", 'a', 'a.rowid', 't.fk_author'),
			'join alias' => fn (): Query => $this->articles()->join('author', "a\n", 'a.rowid', 't.fk_author'),
			'where column' => fn (): Query => $this->articles()->where("title\n", 'x'),
			'aliased column' => fn (): Query => $this->articles()->where("t.title\n", 'x'),
			'query alias' => fn (): Query => new Query((new MappingReader())->read(Author::class), $this->grammar(), null, null, "t\n"),
		];
		foreach ($invalid as $label => $attempt) {
			try {
				$attempt();
				self::fail('Accepted: '.$label);
			} catch (\InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testOrderDirectionFallsBackToAsc(): void
	{
		$compiled = $this->authors()->orderBy('name', 'desc; DROP TABLE x')->orderBy('id', ' DESC ')->orderBy('t.name', 'asc')->orderBy('rowid', 'down')->compile();

		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t ORDER BY t.name ASC, t.rowid DESC, t.name ASC, t.rowid ASC', [], $compiled);
	}

	public function testCount(): void
	{
		$compiled = $this->authors()->where('name', 'a')->orderBy('name')->limit(10)->offset(20)->compileCount();
		$this->assertCompiled('SELECT COUNT(*) AS total FROM @author AS t WHERE t.name = :p1', ['p1' => 'a'], $compiled);

		$distinct = $this->articles()->distinct()->select('authorId')->where('featured', true)->orderBy('authorId')->limit(5);
		$this->assertCompiled(
			'SELECT DISTINCT t.fk_author FROM @article AS t WHERE t.entity IN (:entities) AND t.featured = :p1 ORDER BY t.fk_author ASC LIMIT 5',
			['entities' => [1, 2], 'p1' => 1],
			$distinct->compile()
		);
		$this->assertCompiled(
			'SELECT COUNT(*) AS total FROM (SELECT DISTINCT t.fk_author FROM @article AS t WHERE t.entity IN (:entities) AND t.featured = :p1) AS counted',
			['entities' => [1, 2], 'p1' => 1],
			$distinct->compileCount()
		);

		$grouped = $this->articles()->withoutEntityScope()->select('authorId')->groupBy('authorId');
		$this->assertCompiled(
			'SELECT COUNT(*) AS total FROM (SELECT t.fk_author FROM @article AS t GROUP BY t.fk_author) AS counted',
			[],
			$grouped->compileCount()
		);
	}

	public function testEntityScopeAppliedAndRemovable(): void
	{
		$query = $this->articles()->select('id')->where('title', 'x')->orWhere('title', 'y');

		$this->assertCompiled(
			'SELECT t.rowid FROM @article AS t WHERE t.entity IN (:entities) AND (t.title = :p1 OR t.title = :p2)',
			['entities' => [1, 2], 'p1' => 'x', 'p2' => 'y'],
			$query->compile()
		);
		self::assertSame(['blog_article'], $this->entityScope->askedElements);

		$this->assertCompiled(
			'SELECT t.rowid FROM @article AS t WHERE t.title = :p1 OR t.title = :p2',
			['p1' => 'x', 'p2' => 'y'],
			$query->withoutEntityScope()->compile()
		);

		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t', [], $this->authors()->compile());
		self::assertSame(['blog_article'], $this->entityScope->askedElements, 'No entity column: no scope asked');

		$noEntity = new Query((new MappingReader())->read(Article::class), $this->grammar(), new FakeEntityScope([]));
		$this->assertCompiled('SELECT t.rowid FROM @article AS t WHERE t.entity IN (:entities)', ['entities' => []], $noEntity->select('id')->compile());

		$this->expectException(\LogicException::class);
		(new Query((new MappingReader())->read(Article::class), $this->grammar()))->compile();
	}

	public function testLegacyFragmentPlacement(): void
	{
		$compiled = $this->authors()->select('name')
			->join('tag', 'tg', 'tg.rowid', 't.rowid')
			->where('name', 'a')
			->orWhere('name', 'b')
			->legacyFragment(' AND ef.level > 2', ', ef.level', ' LEFT JOIN llx_author_extrafields AS ef ON ef.fk_object = t.rowid')
			->legacyFragment("ef.code <> 'x'")
			->legacyFragment(' or ef.level IS NULL')
			->orderBy('name')
			->compile();

		$this->assertCompiled(
			'SELECT t.name, ef.level FROM @author AS t INNER JOIN @tag AS tg ON tg.rowid = t.rowid'
			.' LEFT JOIN llx_author_extrafields AS ef ON ef.fk_object = t.rowid'
			." WHERE (t.name = :p1 OR t.name = :p2) AND ef.level > 2 AND (ef.code <> 'x') or ef.level IS NULL ORDER BY t.name ASC",
			['p1' => 'a', 'p2' => 'b'],
			$compiled
		);

		$this->assertCompiled(
			'SELECT t.rowid, t.name FROM @author AS t WHERE 1 = 1 AND ef.level > 2',
			[],
			$this->authors()->legacyFragment('AND ef.level > 2')->compile()
		);
		$this->assertCompiled(
			'SELECT t.rowid, t.name FROM @author AS t WHERE 1 = 1 AND (ef.level > 2)',
			[],
			$this->authors()->legacyFragment('ef.level > 2')->compile()
		);
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t', [], $this->authors()->legacyFragment()->compile());
		$this->assertCompiled(
			'SELECT COUNT(*) AS total FROM @author AS t LEFT JOIN x ON 1 = 1 WHERE 1 = 1 AND (ef.level > 2)',
			[],
			$this->authors()->legacyFragment('ef.level > 2', ', ef.level', ' LEFT JOIN x ON 1 = 1')->compileCount()
		);
	}

	public function testWithValidatesRelations(): void
	{
		self::assertSame(['author', 'tags'], $this->articles()->with('author')->with('tags', 'author')->relations());
	}

	public function testExecutionWithoutExecutorThrows(): void
	{
		$attempts = [
			'get' => fn (): mixed => $this->authors()->get(),
			'first' => fn (): mixed => $this->authors()->first(),
			'count' => fn (): mixed => $this->authors()->count(),
			'exists' => fn (): mixed => $this->authors()->exists(),
			'paginate' => fn (): mixed => $this->authors()->paginate(1, 25),
			'rows' => fn (): mixed => $this->authors()->rows(),
		];
		foreach ($attempts as $label => $attempt) {
			try {
				$attempt();
				self::fail('Executed without executor: '.$label);
			} catch (\LogicException $e) {
				self::assertStringContainsString('executor', $e->getMessage());
			}
		}
	}

	public function testInvalidLimitAndAliasThrow(): void
	{
		foreach ([static fn (Query $query): Query => $query->limit(-1), static fn (Query $query): Query => $query->offset(-1)] as $attempt) {
			try {
				$attempt($this->authors());
				self::fail('Negative limit accepted');
			} catch (\InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}

		$this->expectException(\InvalidArgumentException::class);
		new Query((new MappingReader())->read(Author::class), $this->grammar(), null, null, 't; DROP');
	}

	protected function authors(string $alias = 't'): Query
	{
		return new Query((new MappingReader())->read(Author::class), $this->grammar(), $this->entityScope, null, $alias);
	}

	protected function articles(string $alias = 't'): Query
	{
		return new Query((new MappingReader())->read(Article::class), $this->grammar(), $this->entityScope, null, $alias);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	protected function assertCompiled(string $sql, array $params, CompiledQuery $compiled): void
	{
		self::assertSame($sql, $compiled->sql);
		self::assertSame($params, $compiled->params);
	}
}
