<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm;

use Dolibarr\Core\Orm\Grammar;
use Dolibarr\Core\Orm\MysqlGrammar;

final class QueryMysqlTest extends QueryTestCase
{
	protected function grammar(): Grammar
	{
		return new MysqlGrammar();
	}

	public function testLimitOffset(): void
	{
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t ORDER BY t.name ASC LIMIT 25 OFFSET 50', [], $this->authors()->orderBy('name')->limit(25)->offset(50)->compile());
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t LIMIT 25', [], $this->authors()->limit(25)->compile());
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t LIMIT 0', [], $this->authors()->limit(0)->compile());
		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t LIMIT 18446744073709551615 OFFSET 50', [], $this->authors()->offset(50)->compile());
	}

	public function testCaseInsensitiveLike(): void
	{
		$compiled = $this->authors()->caseInsensitive()->where('name', 'like', 'a%')->where('name', 'not like', 'b%')->compile();

		$this->assertCompiled('SELECT t.rowid, t.name FROM @author AS t WHERE t.name LIKE :p1 AND t.name NOT LIKE :p2', ['p1' => 'a%', 'p2' => 'b%'], $compiled);
	}

	public function testLegacyFragmentNotConvertedOnMysql(): void
	{
		$compiled = $this->authors()->legacyFragment("AND GROUP_CONCAT(t.name SEPARATOR ',') <> ''")->compile();

		self::assertSame("SELECT t.rowid, t.name FROM @author AS t WHERE 1 = 1 AND GROUP_CONCAT(t.name SEPARATOR ',') <> ''", $compiled->sql);
	}
}
