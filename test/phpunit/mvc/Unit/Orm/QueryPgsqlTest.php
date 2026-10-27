<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm;

use Dolibarr\Core\Orm\Grammar;
use Dolibarr\Core\Orm\MappingReader;
use Dolibarr\Core\Orm\PgsqlGrammar;
use Dolibarr\Core\Orm\Query;
use Dolibarr\Tests\Unit\Orm\Fixture\Author;

final class QueryPgsqlTest extends QueryTestCase
{
	/** @var list<string> */
	private array $converted = [];

	protected function grammar(): Grammar
	{
		return new PgsqlGrammar(static fn (string $sql): string => $sql);
	}

	public function testLimitOffset(): void
	{
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t ORDER BY t.name ASC LIMIT 25 OFFSET 50', [], $this->authors()->orderBy('name')->limit(25)->offset(50)->compile());
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t LIMIT 25', [], $this->authors()->limit(25)->compile());
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t LIMIT 0', [], $this->authors()->limit(0)->compile());
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t OFFSET 50', [], $this->authors()->offset(50)->compile());
	}

	public function testCaseInsensitiveLike(): void
	{
		$sensitive = $this->authors()->where('name', 'like', 'a%')->where('name', 'not like', 'b%')->compile();
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t WHERE t.name LIKE :p1 AND t.name NOT LIKE :p2', ['p1' => 'a%', 'p2' => 'b%'], $sensitive);

		$insensitive = $this->authors()->caseInsensitive()->where('name', 'like', 'a%')->where(static function (Query $group): void {
			$group->where('name', 'not like', 'b%');
		})->compile();
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t WHERE t.name ILIKE :p1 AND (t.name NOT ILIKE :p2)', ['p1' => 'a%', 'p2' => 'b%'], $insensitive);
	}

	public function testLegacyFragmentConvertedOnPgsql(): void
	{
		$grammar = new PgsqlGrammar(function (string $sql): string {
			$this->converted[] = $sql;

			return str_replace('IFNULL(', 'COALESCE(', $sql);
		});
		$query = new Query((new MappingReader())->read(Author::class), $grammar);

		$compiled = $query->select('name')->where('name', 'like', 'a%')
			->legacyFragment(' AND IFNULL(ef.level, 0) > 2', ', IFNULL(ef.level, 0) AS level', ' LEFT JOIN llx_x AS ef ON 1 = 1')
			->legacyFragment('', '', '')
			->compile();

		self::assertSame(
			'SELECT t.name, COALESCE(ef.level, 0) AS level FROM @author AS t LEFT JOIN llx_x AS ef ON 1 = 1'
			.' WHERE t.name LIKE :p1 AND COALESCE(ef.level, 0) > 2',
			$compiled->sql
		);
		self::assertSame([', IFNULL(ef.level, 0) AS level', ' LEFT JOIN llx_x AS ef ON 1 = 1', ' AND IFNULL(ef.level, 0) > 2'], $this->converted, 'Empty fragments are not converted');
		self::assertStringNotContainsString('COALESCE', $this->authors()->where('name', 'IFNULL(')->compile()->sql);
	}
}
