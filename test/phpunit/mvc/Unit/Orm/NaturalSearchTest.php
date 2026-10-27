<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm;

use Dolibarr\Core\Orm\MappingReader;
use Dolibarr\Core\Orm\MysqlGrammar;
use Dolibarr\Core\Orm\NaturalSearch;
use Dolibarr\Core\Orm\PgsqlGrammar;
use Dolibarr\Core\Orm\Query;
use Dolibarr\Tests\Unit\Orm\Fixture\Article;
use Dolibarr\Tests\Unit\Orm\Fixture\Author;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SQL and parameters NaturalSearch adds, branch by branch of natural_search() (functions.lib.php l.9834) and
 * dol_natural_search_phone() (phone.lib.php l.173). The rows they find are compared with the legacy functions by
 * NaturalSearchEquivalenceTestCase.
 */
final class NaturalSearchTest extends TestCase
{
	private const AUTHORS = 'SELECT t.rowid, t.name FROM @author AS t WHERE ';
	private const ARTICLES = 'SELECT t.rowid FROM @article AS t WHERE ';

	/**
	 * @return iterable<string, array{string|list<string>, string, int, string, array<string, mixed>}>
	 */
	public static function textCases(): iterable
	{
		yield 'mode 0: one word, contains' => ['name', 'abc', 0, '(t.name LIKE :p1)', ['p1' => '%abc%']];
		yield 'mode 0: trimmed' => ['name', "  abc\t", 0, '(t.name LIKE :p1)', ['p1' => '%abc%']];
		yield 'mode 0: * is %' => ['name', 'a*b', 0, '(t.name LIKE :p1)', ['p1' => '%a%b%']];
		yield 'mode 0: | is OR' => ['name', 'a|b', 0, '(t.name LIKE :p1 OR t.name LIKE :p2)', ['p1' => '%a%', 'p2' => '%b%']];
		yield 'mode 0: spaces around | removed' => ['name', 'a | b', 0, '(t.name LIKE :p1 OR t.name LIKE :p2)', ['p1' => '%a%', 'p2' => '%b%']];
		yield 'mode 0: empty | parts skipped' => ['name', 'a||b|', 0, '(t.name LIKE :p1 OR t.name LIKE :p2)', ['p1' => '%a%', 'p2' => '%b%']];
		yield 'mode 0: spaces are AND' => ['name', 'a b', 0, '(t.name LIKE :p1 AND t.name LIKE :p2)', ['p1' => '%a%', 'p2' => '%b%']];
		yield 'mode 0: double quotes keep spaces' => ['name', '"a b" c', 0, '(t.name LIKE :p1 AND t.name LIKE :p2)', ['p1' => '%a b%', 'p2' => '%c%']];
		yield 'mode 0: single quotes keep spaces' => ['name', "'a b'", 0, '(t.name LIKE :p1)', ['p1' => '%a b%']];
		yield 'mode 0: ! is NOT LIKE or NULL' => ['name', '!a', 0, '((t.name NOT LIKE :p1 OR t.name IS NULL))', ['p1' => '%a%']];
		yield 'mode 0: a criterion starting with ! joins its parts with AND' => [
			'name', '!a|b', 0, '((t.name NOT LIKE :p1 OR t.name IS NULL) AND t.name LIKE :p2)', ['p1' => '%a%', 'p2' => '%b%'],
		];
		yield 'mode 0: ^ anchors the start' => ['name', '^a', 0, '(t.name LIKE :p1)', ['p1' => 'a%']];
		yield 'mode 0: $ anchors the end' => ['name', 'a$', 0, '(t.name LIKE :p1)', ['p1' => '%a']];
		yield 'mode 0: leading $ anchors the start too' => ['name', '$abc', 0, '(t.name LIKE :p1)', ['p1' => 'abc%']];
		yield 'mode 0: ^a$ is an equality pattern' => ['name', '^a$', 0, '(t.name LIKE :p1)', ['p1' => 'a']];
		yield 'mode 0: ^$ is empty or NULL' => ['name', '^$', 0, '((t.name LIKE :p1 OR t.name IS NULL))', ['p1' => '']];
		yield 'mode 0: ^$ joined with AND' => ['name', 'a|^$', 0, '(t.name LIKE :p1 AND (t.name LIKE :p2 OR t.name IS NULL))', ['p1' => '%a%', 'p2' => '']];
		yield 'mode 0: !^a' => ['name', '!^a', 0, '((t.name NOT LIKE :p1 OR t.name IS NULL))', ['p1' => '%^a%']];
		yield 'mode 0: ^!a' => ['name', '^!a', 0, '(t.name LIKE :p1)', ['p1' => '!a%']];
		yield 'mode 0: 0 is a value' => ['name', '0', 0, '(t.name LIKE :p1)', ['p1' => '%0%']];
		yield 'mode 0: % and _ stay wildcards (legacy escape() leaves them)' => ['name', '% _', 0, '(t.name LIKE :p1 AND t.name LIKE :p2)', ['p1' => '%%%', 'p2' => '%_%']];
		yield 'mode 0: hostile value bound, split like the legacy' => [
			'name', "a' OR '1'='1", 0, '(t.name LIKE :p1 AND t.name LIKE :p2 AND t.name LIKE :p3 AND t.name LIKE :p4)',
			['p1' => "%a'%", 'p2' => '%OR%', 'p3' => '%1%', 'p4' => "%='1%"],
		];
		yield 'mode 0: one criterion OR between parts keeps the legacy precedence' => [
			'name', 'a|b c', 0, '(t.name LIKE :p1 OR t.name LIKE :p2 AND t.name LIKE :p3)', ['p1' => '%a%', 'p2' => '%b%', 'p3' => '%c%'],
		];
		yield 'mode 0: rowid is an equality' => ['id', '5', 0, '((t.rowid = :p1))', ['p1' => 5.0]];
		yield 'mode 0: non numeric rowid is 0' => ['t.rowid', 'abc', 0, '((t.rowid = :p1))', ['p1' => 0]];
		yield 'mode 0: rowid OR' => ['id', '5|x', 0, '((t.rowid = :p1) OR (t.rowid = :p2))', ['p1' => 5.0, 'p2' => 0]];
		yield 'other modes search text, * kept' => ['name', 'a*b c', 7, '(t.name LIKE :p1 AND t.name LIKE :p2)', ['p1' => '%a*b%', 'p2' => '%c%']];
	}

	/**
	 * @param string|list<string>  $columns
	 * @param array<string, mixed> $params
	 */
	#[DataProvider('textCases')]
	public function testTextModes(string|array $columns, string $value, int $mode, string $where, array $params): void
	{
		$query = (new NaturalSearch('.'))->apply($this->authors(), $columns, $value, $mode);

		self::assertSame(self::AUTHORS.$where, $query->compile()->sql);
		self::assertSame($params, $query->compile()->params);
	}

	/**
	 * @return iterable<string, array{string|list<string>, string, int, string, array<string, mixed>}>
	 */
	public static function articleCases(): iterable
	{
		yield 'mode 0: several columns OR, parenthesised' => [
			['title', 'summary'], 'a', 0, '((t.title LIKE :p1 OR t.summary LIKE :p2))', ['p1' => '%a%', 'p2' => '%a%'],
		];
		yield 'mode 0: several columns and criteria' => [
			['title', 't.summary'], 'a|b c', 0,
			'((t.title LIKE :p1 OR t.title LIKE :p2 OR t.summary LIKE :p3 OR t.summary LIKE :p4) AND (t.title LIKE :p5 OR t.summary LIKE :p6))',
			['p1' => '%a%', 'p2' => '%b%', 'p3' => '%a%', 'p4' => '%b%', 'p5' => '%c%', 'p6' => '%c%'],
		];
		yield 'mode 0: several columns with ! are AND' => [
			['title', 'summary'], '!a', 0, '(((t.title NOT LIKE :p1 OR t.title IS NULL) AND (t.summary NOT LIKE :p2 OR t.summary IS NULL)))', ['p1' => '%a%', 'p2' => '%a%'],
		];
		yield 'mode 1: >=' => ['viewCount', '>=10', 1, '((t.view_count >= :p1))', ['p1' => 10.0]];
		yield 'mode 1: space after the operator removed' => ['viewCount', '>= 10', 1, '((t.view_count >= :p1))', ['p1' => 10.0]];
		yield 'mode 1: <' => ['viewCount', '<5', 1, '((t.view_count < :p1))', ['p1' => 5.0]];
		yield 'mode 1: <=' => ['viewCount', '<=5', 1, '((t.view_count <= :p1))', ['p1' => 5.0]];
		yield 'mode 1: >' => ['viewCount', '>5', 1, '((t.view_count > :p1))', ['p1' => 5.0]];
		yield 'mode 1: = by default' => ['viewCount', '5', 1, '((t.view_count = :p1))', ['p1' => 5.0]];
		yield 'mode 1: =' => ['viewCount', '=5', 1, '((t.view_count = :p1))', ['p1' => 5.0]];
		yield 'mode 1: !=' => ['viewCount', '!=3', 1, '((t.view_count != :p1))', ['p1' => 3.0]];
		yield 'mode 1: <>' => ['viewCount', '<>3', 1, '((t.view_count <> :p1))', ['p1' => 3.0]];
		yield 'mode 1: two criteria are AND' => ['viewCount', '<5 >1', 1, '((t.view_count < :p1) AND (t.view_count > :p2))', ['p1' => 5.0, 'p2' => 1.0]];
		yield 'mode 1: | is OR' => ['viewCount', '1|>=7', 1, '((t.view_count = :p1) OR (t.view_count >= :p2))', ['p1' => 1.0, 'p2' => 7.0]];
		yield 'mode 1: negative number' => ['price', '< -2.5', 1, '((t.price < :p1))', ['p1' => -2.5]];
		yield 'mode 1: thousand separator removed' => ['price', '>1,234.5', 1, '((t.price > :p1))', ['p1' => 1234.5]];
		yield 'mode 1: operator characters inside are removed' => ['price', '1<2', 1, '((t.price < :p1))', ['p1' => 12.0]];
		yield 'mode 1: not a number is false' => ['viewCount', 'abc', 1, '((1 = 2))', []];
		yield 'mode 1: operator unknown to SQL is false' => ['viewCount', '==3', 1, '((1 = 2))', []];
		yield 'mode 1: several columns' => [['viewCount', 'price'], '>5', 1, '(((t.view_count > :p1) OR (t.price > :p2)))', ['p1' => 5.0, 'p2' => 5.0]];
		yield 'mode 2: integer list' => ['id', '1,2,3', 2, '((t.rowid IN (:p1)))', ['p1' => [1, 2, 3]]];
		yield 'mode 2: non digits removed' => ['id', '1a,-2', 2, '((t.rowid IN (:p1)))', ['p1' => [1, -2]]];
		yield 'mode 2: nothing left is IN (0)' => ['id', 'abc', 2, '((t.rowid IN (:p1)))', ['p1' => [0]]];
		yield 'mode 2: spaces are AND' => ['id', '1 2', 2, '((t.rowid IN (:p1)) AND (t.rowid IN (:p2)))', ['p1' => [1], 'p2' => [2]]];
		yield 'mode -2: NOT IN or NULL' => ['authorId', '1,2', -2, '((t.fk_author NOT IN (:p1)) OR t.fk_author IS NULL)', ['p1' => [1, 2]]];
		yield 'mode 3: string list' => ['title', 'x,y', 3, '((t.title IN (:p1)))', ['p1' => ['x', 'y']]];
		yield 'mode 3: spaces kept, values trimmed' => ['title', 'a b , c', 3, '((t.title IN (:p1)))', ['p1' => ['a b', 'c']]];
		yield 'mode 3: ! is NOT IN' => ['title', '!x,!=y', 3, '((t.title NOT IN (:p1)))', ['p1' => ['x', 'y']]];
		yield 'mode 3: IN and NOT IN' => ['title', 'x,!y', 3, '(((t.title IN (:p1)) AND (t.title NOT IN (:p2))))', ['p1' => ['x'], 'p2' => ['y']]];
		yield 'mode 3: values sanitized as DoliDB::sanitize() does' => ['title', "x%,y'z,é\"(", 3, '((t.title IN (:p1)))', ['p1' => ['x', 'yz', 'é']]];
		yield 'mode 3: several columns' => [['title', 'summary'], 'x', 3, '(((t.title IN (:p1)) OR (t.summary IN (:p2))))', ['p1' => ['x'], 'p2' => ['x']]];
		yield 'mode -3: NOT IN or NULL' => ['summary', 'x,!y', -3, '(((t.summary NOT IN (:p1)) AND (t.summary IN (:p2))) OR t.summary IS NULL)', ['p1' => ['x'], 'p2' => ['y']]];
		yield 'mode 4: multiselect value' => [
			'summary', 'a', 4, '((t.summary LIKE :p1 OR (t.summary = :p2) OR t.summary LIKE :p3 OR t.summary LIKE :p4))',
			['p1' => 'a,%', 'p2' => 'a', 'p3' => '%,a', 'p4' => '%,a,%'],
		];
		yield 'mode 4: two values OR, 0 skipped' => [
			'summary', 'a,0,b', 4,
			'(((t.summary LIKE :p1 OR (t.summary = :p2) OR t.summary LIKE :p3 OR t.summary LIKE :p4) OR (t.summary LIKE :p5 OR (t.summary = :p6) OR t.summary LIKE :p7 OR t.summary LIKE :p8)))',
			['p1' => 'a,%', 'p2' => 'a', 'p3' => '%,a', 'p4' => '%,a,%', 'p5' => 'b,%', 'p6' => 'b', 'p7' => '%,b', 'p8' => '%,b,%'],
		];
	}

	/**
	 * @param string|list<string>  $columns
	 * @param array<string, mixed> $params
	 */
	#[DataProvider('articleCases')]
	public function testArticleModes(string|array $columns, string $value, int $mode, string $where, array $params): void
	{
		$query = (new NaturalSearch('.', ','))->apply($this->articles(), $columns, $value, $mode);

		self::assertSame(self::ARTICLES.$where, $query->compile()->sql);
		self::assertSame($params, $query->compile()->params);
	}

	public function testDecimalSeparatorOfTheLanguage(): void
	{
		$search = new NaturalSearch(',', 'Space');

		$query = $search->apply($this->articles(), 'price', '>= 1234,5|< -0,5', 1);
		self::assertSame(self::ARTICLES.'((t.price >= :p1) OR (t.price < :p2))', $query->compile()->sql);
		self::assertSame(['p1' => 1234.5, 'p2' => -0.5], $query->compile()->params);

		$spaced = $search->apply($this->articles(), 'price', '>= 1 234,5|< -0,5', 1);
		self::assertSame(self::ARTICLES.'((t.price >= :p1) AND (t.price = :p2) OR (t.price < :p3))', $spaced->compile()->sql, 'The space splits "1 234,5" before price2num()');
		self::assertSame(['p1' => 1.0, 'p2' => 234.5, 'p3' => -0.5], $spaced->compile()->params);
		self::assertSame(['p1' => 1234.5], $search->apply($this->articles(), 'price', '>=1234,5', 1)->compile()->params);
		self::assertSame(['p1' => 1234.5], $search->apply($this->articles(), 'price', '>=1.234,5', 1)->compile()->params);
	}

	public function testEmptyValueLeavesTheQueryUnchanged(): void
	{
		foreach ([0, 1, 2, -2, 3, -3, 4] as $mode) {
			foreach (['', '   '] as $value) {
				$query = (new NaturalSearch('.'))->apply($this->authors()->where('name', 'x'), ['name', 'id'], $value, $mode);
				self::assertSame(self::AUTHORS.'t.name = :p1', $query->compile()->sql, 'mode '.$mode);
			}
		}
		self::assertSame('SELECT t.rowid, t.name FROM @author AS t', (new NaturalSearch('.'))->applyPhone($this->authors(), 'name', ' ')->compile()->sql);
	}

	public function testNothingSearchableLeavesTheQueryUnchanged(): void
	{
		self::assertSame('SELECT t.rowid, t.name FROM @author AS t', (new NaturalSearch('.'))->apply($this->authors(), 'name', '|', 0)->compile()->sql);
		self::assertSame('SELECT t.rowid, t.name FROM @author AS t', (new NaturalSearch('.'))->apply($this->authors(), 'id', '>', 1)->compile()->sql);
	}

	public function testAddedAfterTheOtherConditionsAndInsideAGroup(): void
	{
		$search = new NaturalSearch('.');
		$query = $search->apply($this->authors()->where('name', 'x')->orWhere('name', 'y'), 'name', 'a|b');
		self::assertSame(self::AUTHORS.'t.name = :p1 OR t.name = :p2 AND (t.name LIKE :p3 OR t.name LIKE :p4)', $query->compile()->sql);

		$grouped = $this->authors()->where(static function (Query $group) use ($search): void {
			$search->apply($group, 'name', 'a');
		});
		self::assertSame(self::AUTHORS.'((t.name LIKE :p1))', $grouped->compile()->sql);
	}

	public function testLikeIsCaseInsensitiveOnPgsqlOnly(): void
	{
		$query = new Query((new MappingReader())->read(Article::class), new PgsqlGrammar(static fn (string $sql): string => $sql));
		$query->withoutEntityScope()->select('id');

		(new NaturalSearch('.'))->apply($query, 'summary', '!a b', 0);
		(new NaturalSearch('.'))->apply($query, 'summary', 'c', 4);

		self::assertSame(
			self::ARTICLES.'((t.summary NOT ILIKE :p1 OR t.summary IS NULL) AND t.summary ILIKE :p2)'
			.' AND ((t.summary ILIKE :p3 OR (t.summary = :p4) OR t.summary ILIKE :p5 OR t.summary ILIKE :p6))',
			$query->compile()->sql
		);
	}

	public function testUnknownOrSecretColumnThrows(): void
	{
		foreach (['nope', 't.pass_crypted', 'api_key', 'name; DROP', '(SELECT 1)', 'zz.name'] as $column) {
			try {
				(new NaturalSearch('.'))->apply($this->authors(), ['name', $column], 'a');
				self::fail('Column accepted: '.$column);
			} catch (\InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testPhone(): void
	{
		$search = new NaturalSearch('.', ',', ['0', '', '8']);

		$query = $search->applyPhone($this->authors(), 'name', ' 01 23.45-67(89) ');
		self::assertSame(self::AUTHORS.'(t.name LIKE :p1 OR t.name LIKE :p2)', $query->compile()->sql);
		self::assertSame(['p1' => '%0123456789%', 'p2' => '%123456789%'], $query->compile()->params);

		self::assertSame(['p1' => '%+33123%'], $search->applyPhone($this->authors(), 'name', '+33 1 23')->compile()->params, 'No trunk prefix after +');
		self::assertSame(['p1' => '%()%'], $search->applyPhone($this->authors(), 'name', '()')->compile()->params, 'Nothing left: the value as typed');
		self::assertSame(['p1' => '%0%'], $search->applyPhone($this->authors(), 'name', '0')->compile()->params, 'A candidate left empty is dropped');
		self::assertSame(
			['p1' => '%812%', 'p2' => '%12%'],
			$search->applyPhone($this->authors(), ['name'], '812')->compile()->params
		);
		self::assertSame(['p1' => '%0612%'], (new NaturalSearch('.'))->applyPhone($this->authors(), 'name', '06 12')->compile()->params, 'No trunk prefix known');
	}

	private function authors(): Query
	{
		return new Query((new MappingReader())->read(Author::class), new MysqlGrammar());
	}

	private function articles(): Query
	{
		return (new Query((new MappingReader())->read(Article::class), new MysqlGrammar()))->withoutEntityScope()->select('id');
	}
}
