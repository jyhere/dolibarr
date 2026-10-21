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
		$connection = new Connection($GLOBALS['db']);
		$row = $connection->fetchOne("SELECT login FROM ".$connection->prefix()."user WHERE rowid = 1");
		self::assertNotNull($row);
		self::assertSame($GLOBALS['user']->login, $row->login);
		self::assertSame("O\\'Neil", $connection->escape("O'Neil"));
	}

	public function testRealTransactionRunsWithoutException(): void
	{
		$connection = new Connection($GLOBALS['db']);
		$connection->begin();
		$connection->begin();
		$connection->commit();
		$connection->rollback();
		self::assertSame(0, (int) $GLOBALS['db']->transaction_opened);
	}

	public function testFailedBeginCommitOrRollbackThrowsTheDriverError(): void
	{
		$connection = new Connection($this->failingDb());
		foreach (['begin', 'commit', 'rollback'] as $method) {
			try {
				$connection->$method();
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
