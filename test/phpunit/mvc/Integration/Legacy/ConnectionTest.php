<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
	public function testFetchOneAndEscape(): void
	{
		$c = new Connection($GLOBALS['db']);
		$row = $c->fetchOne("SELECT login FROM ".$c->prefix()."user WHERE rowid = 1");
		self::assertNotNull($row);
		self::assertSame($GLOBALS['user']->login, $row->login);
		self::assertSame("O\\'Neil", $c->escape("O'Neil"));
	}

	public function testRealTransactionRunsWithoutException(): void
	{
		$c = new Connection($GLOBALS['db']);
		$c->begin();
		$c->begin();
		$c->commit();
		$c->rollback();
		self::assertSame(0, (int) $GLOBALS['db']->transaction_opened);
	}

	public function testFailedBeginCommitOrRollbackThrowsTheDriverError(): void
	{
		$c = new Connection($this->failingDb());
		foreach (['begin', 'commit', 'rollback'] as $method) {
			try {
				$c->$method();
				self::fail($method.'() should throw');
			} catch (DatabaseException $e) {
				self::assertSame('forced '.$method.' failure', $e->getMessage());
			}
		}
	}

	/**
	 * A driver whose BEGIN, COMMIT and ROLLBACK fail as DoliDB reports it: 0 (begin, commit) or false (rollback,
	 * the result of query()), with lasterror set.
	 */
	private function failingDb(): \DoliDB
	{
		return new class extends \DoliDBMysqli {
			public function __construct()
			{
			}

			public function begin($textinlog = '')
			{
				$this->lasterror = 'forced begin failure';

				return 0;
			}

			public function commit($log = '')
			{
				$this->lasterror = 'forced commit failure';

				return 0;
			}

			public function rollback($log = '')
			{
				$this->lasterror = 'forced rollback failure';

				return false;
			}
		};
	}

	public function testInvalidSqlThrowsDatabaseException(): void
	{
		$this->expectException(DatabaseException::class);
		(new Connection($GLOBALS['db']))->fetchAll('SELECT * FROM nope_table');
	}
}
