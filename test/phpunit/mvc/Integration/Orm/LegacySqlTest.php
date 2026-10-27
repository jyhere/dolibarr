<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm;

use Dolibarr\Core\Orm\MappingReader;
use Dolibarr\Core\Orm\PgsqlGrammar;
use Dolibarr\Core\Orm\Query;
use Dolibarr\Tests\Unit\Orm\Fixture\Article;
use Dolibarr\Tests\Unit\Orm\Fixture\Author;
use Dolibarr\Tests\Unit\Orm\Fixture\FakeEntityScope;
use PHPUnit\Framework\TestCase;

/**
 * Query::toLegacySql(): the copy of the SQL handed to legacy code, values interpolated as LegacyDriver does, on
 * the real DoliDB (its escape()). An integration test: the unit suite loads no DoliDB.
 */
final class LegacySqlTest extends TestCase
{
	public function testInterpolatesEscapedValues(): void
	{
		$db = $GLOBALS['db'];
		self::assertInstanceOf(\DoliDB::class, $db);
		$prefix = $db->prefix();

		$query = $this->articles()->select('id', 'title')
			->where('title', "it's")
			->whereRaw('COALESCE(t.summary, :nothing) IS NULL', ['nothing' => null])
			->where('viewCount', '>', '5')
			->where('price', '>', -1.5)
			->where('createdAt', '>=', new \DateTimeImmutable('2026-03-29 02:30:00'))
			->where('publishedOn', new \DateTimeImmutable('2026-03-29 23:00:00'))
			->whereIn('authorId', [3, 4])
			->legacyFragment(" AND t.summary <> 'legacy'", '', ' LEFT JOIN '.$prefix.'article_extrafields AS ef ON ef.fk_object = t.rowid')
			->orderBy('title', 'DESC')
			->limit(10)
			->offset(20);

		self::assertSame(
			'SELECT t.rowid, t.title FROM '.$prefix.'article AS t LEFT JOIN '.$prefix.'article_extrafields AS ef ON ef.fk_object = t.rowid'
			." WHERE t.entity IN (1, 2) AND t.title = 'it\\'s' AND (COALESCE(t.summary, NULL) IS NULL) AND t.view_count > 5"
			." AND t.price > (-1.5) AND t.datec >= '2026-03-29 02:30:00' AND t.published_on = '2026-03-29'"
			." AND t.fk_author IN (3, 4) AND t.summary <> 'legacy' ORDER BY t.title DESC LIMIT 10 OFFSET 20",
			$query->toLegacySql($db)
		);
	}

	public function testMysqlDialectWhateverTheQueryGrammar(): void
	{
		$fragments = [];
		$query = new Query((new MappingReader())->read(Article::class), new PgsqlGrammar(static function (string $sql) use (&$fragments): string {
			$fragments[] = $sql;

			return 'converted';
		}), new FakeEntityScope([]));

		$sql = $query->select('id')->caseInsensitive()->where('title', 'like', '%a%')->offset(5)->legacyFragment('AND IFNULL(t.summary, 0) = 0')->toLegacySql($GLOBALS['db']);

		self::assertSame(
			'SELECT t.rowid FROM '.$GLOBALS['db']->prefix()."article AS t WHERE t.entity IN (NULL) AND t.title LIKE '%a%' AND IFNULL(t.summary, 0) = 0 LIMIT 18446744073709551615 OFFSET 5",
			$sql
		);
		self::assertSame([], $fragments, 'The legacy DoliDB converts the fragments itself');
	}

	public function testComputedColumnsAliasesAndNotExists(): void
	{
		$db = $GLOBALS['db'];
		$prefix = $db->prefix();
		$authors = new Query((new MappingReader())->read(Author::class), new PgsqlGrammar(static fn (string $sql): string => $sql), null, null, 'a');

		$sql = $this->articles()
			->select('title AS heading')
			->selectRaw("CASE WHEN t.summary IS NOT NULL AND t.summary <> '' THEN 1 ELSE 0 END", 'has_summary')
			->selectRaw('CASE WHEN t.title = :label THEN 1 ELSE 0 END', 'is_label', ['label' => "o'clock"])
			->whereNotExists($authors->select('id')->whereRaw('a.rowid = t.fk_author')->where('name', 'x'))
			->toLegacySql($db);

		self::assertSame(
			"SELECT t.title AS heading, (CASE WHEN t.summary IS NOT NULL AND t.summary <> '' THEN 1 ELSE 0 END) AS has_summary,"
			." (CASE WHEN t.title = 'o\\'clock' THEN 1 ELSE 0 END) AS is_label FROM ".$prefix.'article AS t'
			.' WHERE t.entity IN (1, 2) AND NOT EXISTS (SELECT a.rowid FROM '.$prefix."author AS a WHERE (a.rowid = t.fk_author) AND a.name = 'x')",
			$sql
		);
	}

	private function articles(): Query
	{
		return new Query((new MappingReader())->read(Article::class), new PgsqlGrammar(static fn (string $sql): string => $sql), new FakeEntityScope());
	}
}
