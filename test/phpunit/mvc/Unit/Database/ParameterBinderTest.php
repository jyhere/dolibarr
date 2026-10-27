<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Database;

use Dolibarr\Core\Database\ParameterBinder;
use Dolibarr\Tests\Unit\Database\Fixture\BinderStatus;
use PHPUnit\Framework\TestCase;

final class ParameterBinderTest extends TestCase
{
	private ParameterBinder $binder;

	protected function setUp(): void
	{
		$this->binder = new ParameterBinder('llx_');
	}

	public function testNamedParametersBecomePositional(): void
	{
		$statement = $this->binder->bind(
			'SELECT rowid FROM llx_user WHERE login = :login AND statut = :status AND (fk_user = :login_owner OR rowid = :status)',
			['login' => 'admin', 'status' => 1, 'login_owner' => null]
		);

		self::assertSame('SELECT rowid FROM llx_user WHERE login = ? AND statut = ? AND (fk_user = ? OR rowid = ?)', $statement->sql);
		self::assertSame(['admin', 1, null, 1], $statement->values);
	}

	public function testTablePrefix(): void
	{
		$statement = $this->binder->bind("SELECT u.rowid FROM @user as u JOIN @user_rights ur ON ur.fk_user = u.rowid WHERE u.email = 'a@user' AND u.note = '@user'", []);

		self::assertSame("SELECT u.rowid FROM llx_user as u JOIN llx_user_rights ur ON ur.fk_user = u.rowid WHERE u.email = 'a@user' AND u.note = '@user'", $statement->sql);
		self::assertSame([], $statement->values);
	}

	public function testColonInsideStringLiteralIsNotAParameter(): void
	{
		$statement = $this->binder->bind(
			"SELECT ':skip', 'it''s :skip', 'back\\' :skip', datec::date, \":skip\" FROM @user WHERE login = :login",
			['login' => 'admin']
		);

		self::assertSame("SELECT ':skip', 'it''s :skip', 'back\\' :skip', datec::date, \":skip\" FROM llx_user WHERE login = ?", $statement->sql);
		self::assertSame(['admin'], $statement->values);
	}

	public function testBackslashEscapesAQuoteInTheMysqlDialectOnly(): void
	{
		$sql = "SELECT 'it\\' :skip', :value";

		$mysql = (new ParameterBinder('llx_', 'mysql'))->bind($sql, ['value' => 1]);
		self::assertSame("SELECT 'it\\' :skip', ?", $mysql->sql);

		// pgsql: the backslash is ordinary, the literal 'it\' ends there and :skip is a parameter
		$pgsql = (new ParameterBinder('llx_', 'pgsql'))->bind($sql, ['skip' => 2, 'value' => 1]);
		self::assertSame("SELECT 'it\\' ?', ?", $pgsql->sql);
		self::assertSame([2, 1], $pgsql->values);
	}

	public function testQuoteInsideACommentFailsSafe(): void
	{
		// Comments are not tokenised (SqlLiterals): the apostrophe opens a literal up to the next quote
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Unused SQL parameter.*:login/');
		$this->binder->bind("SELECT rowid -- the user's row\nFROM @user WHERE login = :login AND statut = '1'", ['login' => 'admin']);
	}

	public function testArrayExpands(): void
	{
		$statement = $this->binder->bind('SELECT rowid FROM @user WHERE rowid IN (:ids) AND entity = :entity', ['ids' => [3, 7], 'entity' => 1]);

		self::assertSame('SELECT rowid FROM llx_user WHERE rowid IN (?, ?) AND entity = ?', $statement->sql);
		self::assertSame([3, 7, 1], $statement->values);
	}

	public function testEmptyArrayIsFalseCondition(): void
	{
		$statement = $this->binder->bind('SELECT rowid FROM @user WHERE entity IN (:entities) AND statut = :status', ['entities' => [], 'status' => 1]);

		self::assertSame('SELECT rowid FROM llx_user WHERE entity IN (NULL) AND statut = ?', $statement->sql);
		self::assertSame([1], $statement->values);
	}

	public function testTypes(): void
	{
		$date = new \DateTimeImmutable('2026-10-22 14:05:09', new \DateTimeZone('Europe/Paris'));
		$statement = $this->binder->bind(
			'VALUES (:yes, :no, :status, :date, :amount, :label, :list)',
			['yes' => true, 'no' => false, 'status' => BinderStatus::Enabled, 'date' => $date, 'amount' => 1.5, 'label' => 'text', 'list' => [true, BinderStatus::Disabled]]
		);

		self::assertSame('VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $statement->sql);
		self::assertSame([1, 0, 'enabled', '2026-10-22 14:05:09', 1.5, 'text', 1, 'disabled'], $statement->values);
	}

	public function testMissingAndExtraParameterThrow(): void
	{
		try {
			$this->binder->bind('SELECT rowid FROM @user WHERE login = :login AND entity = :entity', ['login' => 'admin']);
			self::fail('A missing parameter must throw');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('entity', $e->getMessage());
		}

		try {
			$this->binder->bind('SELECT rowid FROM @user WHERE login = :login', ['login' => 'admin', 'unused_param' => 1]);
			self::fail('An extra parameter must throw');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('unused_param', $e->getMessage());
		}
	}

	public function testPositionalPlaceholderIsRefused(): void
	{
		self::assertSame("SELECT '?' FROM llx_user", $this->binder->bind("SELECT '?' FROM @user", [])->sql);

		$this->expectException(\InvalidArgumentException::class);
		$this->binder->bind('SELECT rowid FROM @user WHERE login = ?', []);
	}

	public function testUnsupportedTypeThrows(): void
	{
		foreach (['object' => new \stdClass(), 'nested' => [[1, 2]]] as $name => $value) {
			try {
				$this->binder->bind('SELECT :'.$name, [$name => $value]);
				self::fail('Parameter '.$name.' must throw');
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString($name, $e->getMessage());
			}
		}
	}
}
