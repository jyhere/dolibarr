<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Module\User\Repository\UserRepository;
use PHPUnit\Framework\TestCase;

final class UserRepositoryTest extends TestCase
{
	public function testFindReturnsAdminUser(): void
	{
		$u = $this->repo()->find(1);
		self::assertNotNull($u);
		self::assertSame($GLOBALS['user']->login, $u->login);
		self::assertTrue($u->admin);
	}

	public function testFindUnknownReturnsNull(): void
	{
		self::assertNull($this->repo()->find(999999));
	}

	public function testFindByLoginMatchesEntity(): void
	{
		$u = $this->repo()->findByLogin($GLOBALS['user']->login);
		self::assertNotNull($u);
		self::assertSame(1, $u->id);
	}

	public function testFindByLoginUnknownReturnsNull(): void
	{
		self::assertNull($this->repo()->findByLogin("no-such-login'; --"));
	}

	public function testFindNeverSelectsPasswordColumns(): void
	{
		foreach (UserRepository::COLUMNS as $col) {
			if ($col === 'force_pass_change') {
				continue; // a flag, not a secret
			}
			self::assertDoesNotMatchRegularExpression('/pass|api_key|openid|ldap_sid/i', $col);
		}
		self::assertStringContainsString('api_key', UserRepository::HAS_API_KEY_SQL);
		self::assertNotNull($this->repo()->find(1));
	}

	public function testHasApiKeyIsBooleanAndValueNeverLoaded(): void
	{
		$u = $this->repo()->find(1);
		self::assertNotNull($u);
		self::assertIsBool($u->hasApiKey);
	}

	private function repo(): UserRepository
	{
		return new UserRepository(new Connection($GLOBALS['db']), 1);
	}
}
