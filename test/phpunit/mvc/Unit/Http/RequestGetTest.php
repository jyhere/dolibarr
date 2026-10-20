<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Http;

use Dolibarr\Core\Http\Request;
use Dolibarr\Tests\Unit\Http\Fixture\FakeInputFilter;
use PHPUnit\Framework\TestCase;

final class RequestGetTest extends TestCase
{
	public function testGetWithoutFilterThrows(): void
	{
		$this->expectException(\LogicException::class);
		(new Request('GET', '/', [], [], [], []))->get('x');
	}

	public function testGetIntWithoutFilterThrows(): void
	{
		$this->expectException(\LogicException::class);
		(new Request('GET', '/', [], [], [], []))->getInt('x');
	}

	public function testIsSetWithoutFilterThrows(): void
	{
		$this->expectException(\LogicException::class);
		(new Request('GET', '/', [], [], [], []))->isSet('x');
	}

	public function testGetDelegatesToInjectedFilter(): void
	{
		$f = new FakeInputFilter(['login' => 'jdoe']);
		self::assertSame('jdoe', (new Request('POST', '/login', [], [], [], []))->withInputFilter($f)->get('login', 'alphanohtml'));
		self::assertSame(['login', 'alphanohtml'], $f->lastCall);
	}

	public function testGetReadsGetFirst(): void
	{
		$f = new FakeInputFilter(['x' => 'v']);
		(new Request('GET', '/', [], [], [], []))->withInputFilter($f)->get('x');
		self::assertSame(0, $f->lastMethod);
	}

	public function testGetPostUsesPostFirst(): void
	{
		$f = new FakeInputFilter(['username' => 'admin']);
		$r = (new Request('POST', '/login', [], [], [], []))->withInputFilter($f);
		self::assertSame('admin', $r->getPost('username', 'alpha'));
		self::assertSame(['username', 'alpha'], $f->lastCall);
		self::assertSame(3, $f->lastMethod);
		self::assertSame('none', $r->getPost('absent', default: 'none'));
		self::assertSame(['absent', 'alphanohtml'], $f->lastCall);
	}

	public function testGetPostWithoutFilterThrows(): void
	{
		$this->expectException(\LogicException::class);
		(new Request('POST', '/', [], [], [], []))->getPost('x');
	}

	public function testGetDefaultsToAlphanohtmlAndReturnsTheDefaultWhenMissing(): void
	{
		$f = new FakeInputFilter([]);
		self::assertSame('none', (new Request('GET', '/', [], [], [], []))->withInputFilter($f)->get('x', default: 'none'));
		self::assertSame(['x', 'alphanohtml'], $f->lastCall);
	}

	public function testGetIntUsesTheIntFilterAndCasts(): void
	{
		$f = new FakeInputFilter(['id' => '12']);
		$r = (new Request('GET', '/', [], [], [], []))->withInputFilter($f);
		self::assertSame(12, $r->getInt('id'));
		self::assertSame(['id', 'int'], $f->lastCall);
		self::assertSame(7, $r->getInt('missing', 7));
	}

	public function testIsSetDelegatesToInjectedFilter(): void
	{
		$r = (new Request('GET', '/', [], [], [], []))->withInputFilter(new FakeInputFilter(['a' => '']));
		self::assertTrue($r->isSet('a'));
		self::assertFalse($r->isSet('b'));
	}

	public function testWithInputFilterReturnsANewInstance(): void
	{
		$r = new Request('GET', '/user/1', ['q' => 'v'], [], [], []);
		$filtered = $r->withInputFilter(new FakeInputFilter([]));
		self::assertNotSame($r, $filtered);
		self::assertSame('/user/1', $filtered->path());
		self::assertSame('v', $filtered->query('q'));
		$this->expectException(\LogicException::class);
		$r->get('q');
	}
}
