<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\LegacySession;
use PHPUnit\Framework\TestCase;

final class LegacySessionTest extends TestCase
{
	/** @var array<mixed>|null */
	private ?array $session = null;

	protected function setUp(): void
	{
		$this->session = $_SESSION ?? null;
		$_SESSION = [];
	}

	protected function tearDown(): void
	{
		if ($this->session === null) {
			unset($_SESSION);
		} else {
			$_SESSION = $this->session;
		}
	}

	public function testSessionRoundTrip(): void
	{
		$legacySession = new LegacySession();
		$legacySession->set('k', 1);
		self::assertTrue($legacySession->has('k'));
		$legacySession->remove('k');
		self::assertFalse($legacySession->has('k'));
	}

	public function testGetReadsTheSessionArray(): void
	{
		$_SESSION['dol_login'] = 'admin';
		$legacySession = new LegacySession();
		self::assertSame('admin', $legacySession->get('dol_login'));
		self::assertSame('none', $legacySession->get('absent', 'none'));
		self::assertNull($legacySession->get('absent'));
	}

	public function testSetWritesTheSessionArray(): void
	{
		(new LegacySession())->set('dol_loginmesg', 'x');
		self::assertSame('x', $_SESSION['dol_loginmesg']);
	}

	public function testANullValueCountsAsAbsentAsWithIsset(): void
	{
		$_SESSION['k'] = null;
		self::assertFalse((new LegacySession())->has('k'));
	}

	public function testWorksWhenNoSessionArrayExists(): void
	{
		unset($_SESSION);
		$legacySession = new LegacySession();
		self::assertFalse($legacySession->has('k'));
		self::assertNull($legacySession->get('k'));
		$legacySession->set('k', 'v');
		self::assertSame('v', $legacySession->get('k'));
	}

	public function testDestroyWithoutActiveSessionEmptiesTheArray(): void
	{
		$_SESSION = ['dol_login' => 'admin'];
		(new LegacySession())->destroy();
		self::assertSame([], $_SESSION);
	}

	public function testRegenerateAndIdWithoutActiveSession(): void
	{
		$legacySession = new LegacySession();
		$legacySession->regenerate();
		self::assertSame('', $legacySession->id());
	}
}
