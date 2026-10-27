<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Database;

use Dolibarr\Core\Database\BoundStatement;
use Dolibarr\Core\Database\DatabaseException;
use PHPUnit\Framework\TestCase;

final class DatabaseExceptionTest extends TestCase
{
	public function testFromStatementKeepsPlaceholdersAndEngineCode(): void
	{
		$exception = DatabaseException::fromStatement(new BoundStatement('SELECT nope FROM llx_user WHERE login = ?', ['admin']), "Unknown column 'nope'", 'DB_ERROR_NOSUCHFIELD');

		self::assertSame("Unknown column 'nope' (SQL: SELECT nope FROM llx_user WHERE login = ?)", $exception->getMessage());
		self::assertSame('DB_ERROR_NOSUCHFIELD', $exception->engineCode());
		self::assertSame(0, $exception->getCode());
		self::assertSame(1064, DatabaseException::fromStatement(new BoundStatement('SELECT', []), 'syntax', 1064)->getCode());
	}

	public function testBoundStringsEchoedByTheEngineAreMasked(): void
	{
		$statement = new BoundStatement('INSERT INTO llx_user (login, pass) VALUES (?, ?)', ['jo', "p4ss'word"]);

		$messages = [
			"Duplicate entry 'p4ss'word' for key 'uk'",
			"near 'p4ss\\'word')' at line 1",
			"near 'p4ss''word'",
		];
		foreach ($messages as $message) {
			$exception = DatabaseException::fromStatement($statement, $message, 1062);
			self::assertStringNotContainsString('p4ss', $exception->getMessage());
			self::assertStringContainsString('***', $exception->getMessage());
		}
	}
}
