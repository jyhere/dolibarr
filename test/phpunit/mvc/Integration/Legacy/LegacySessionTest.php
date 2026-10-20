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
		$s = new LegacySession();
		$s->set('k', 1);
		self::assertTrue($s->has('k'));
		$s->remove('k');
		self::assertFalse($s->has('k'));
	}

	public function testGetReadsTheSessionArray(): void
	{
		$_SESSION['dol_login'] = 'admin';
		$s = new LegacySession();
		self::assertSame('admin', $s->get('dol_login'));
		self::assertSame('none', $s->get('absent', 'none'));
		self::assertNull($s->get('absent'));
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
		$s = new LegacySession();
		self::assertFalse($s->has('k'));
		self::assertNull($s->get('k'));
		$s->set('k', 'v');
		self::assertSame('v', $s->get('k'));
	}

	public function testDestroyWithoutActiveSessionEmptiesTheArray(): void
	{
		$_SESSION = ['dol_login' => 'admin'];
		(new LegacySession())->destroy();
		self::assertSame([], $_SESSION);
	}

	public function testRegenerateAndIdWithoutActiveSession(): void
	{
		$s = new LegacySession();
		$s->regenerate();
		self::assertSame('', $s->id());
	}
}
