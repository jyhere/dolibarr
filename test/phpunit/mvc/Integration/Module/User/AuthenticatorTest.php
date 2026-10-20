<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacySession;
use Dolibarr\Module\User\Security\Authenticator;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
	/** @var array<mixed> */
	private array $session = [];

	protected function setUp(): void
	{
		$this->session = $_SESSION ?? [];
		$_SESSION = [];
	}

	protected function tearDown(): void
	{
		$_SESSION = $this->session;
	}

	public function testAttemptWithGoodPasswordSucceedsAndBadFails(): void
	{
		$a = $this->authenticator();
		$ok = $a->attempt(self::env('DOLIBARR_ADMIN_LOGIN'), self::env('DOLIBARR_ADMIN_PASSWORD'), 1);
		self::assertTrue($ok->ok());
		self::assertFalse($ok->needsTotp());
		self::assertSame(self::env('DOLIBARR_ADMIN_LOGIN'), $ok->login());
		self::assertSame('dolibarr', $ok->authMode());

		$ko = $a->attempt('admin', 'wrong', 1);
		self::assertFalse($ko->ok());
		self::assertFalse($ko->needsTotp());
	}

	public function testAttemptWithEmptyPasswordFails(): void
	{
		self::assertFalse($this->authenticator()->attempt('admin', '', 1)->ok());
	}

	public function testAttemptTotpWithoutPendingLoginFails(): void
	{
		$result = $this->authenticator()->attemptTotp('123456');
		self::assertFalse($result->ok());
		self::assertFalse($result->needsTotp());
	}

	private function authenticator(): Authenticator
	{
		$ctx = LegacyContext::fromGlobals();

		return new Authenticator($ctx, new LegacySession(), new LegacyConfig($ctx), explode(',', (string) $ctx->conf->file->main_authentication));
	}

	private static function env(string $name): string
	{
		$value = getenv($name);
		self::assertIsString($value, $name.' must be set in the php-fpm container environment');

		return $value;
	}
}
