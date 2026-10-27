<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Pgsql\Orm;

use Dolibarr\Core\Orm\PgsqlGrammar;
use Dolibarr\Tests\Pgsql\Support\PgsqlDatabase;
use PHPUnit\Framework\TestCase;

/**
 * PgsqlGrammar::forLegacy(): legacy fragments go through the converter of the real DoliDBPgsql.
 */
final class PgsqlGrammarTest extends TestCase
{
	public function testLegacyFragmentUsesConvertSqlFromMysql(): void
	{
		$db = PgsqlDatabase::doliDb();
		$fragment = " AND GROUP_CONCAT(ef.code SEPARATOR ',') <> '' AND CAST(ef.level as signed) > 2";

		$converted = PgsqlGrammar::forLegacy($db)->legacyFragment($fragment);

		self::assertSame((string) $db->convertSQLFromMysql($fragment), $converted);
		self::assertStringContainsString('STRING_AGG', $converted);
		self::assertStringContainsString('as integer)', $converted);
	}
}
