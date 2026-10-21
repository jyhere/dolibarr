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
		$inputFilter = new FakeInputFilter(['login' => 'jdoe']);
		self::assertSame('jdoe', (new Request('POST', '/login', [], [], [], []))->withInputFilter($inputFilter)->get('login', 'alphanohtml'));
		self::assertSame(['login', 'alphanohtml'], $inputFilter->lastCall);
	}

	public function testGetReadsGetFirst(): void
	{
		$inputFilter = new FakeInputFilter(['x' => 'v']);
		(new Request('GET', '/', [], [], [], []))->withInputFilter($inputFilter)->get('x');
		self::assertSame(0, $inputFilter->lastMethod);
	}

	public function testGetPostUsesPostFirst(): void
	{
		$inputFilter = new FakeInputFilter(['username' => 'admin']);
		$request = (new Request('POST', '/login', [], [], [], []))->withInputFilter($inputFilter);
		self::assertSame('admin', $request->getPost('username', 'alpha'));
		self::assertSame(['username', 'alpha'], $inputFilter->lastCall);
		self::assertSame(3, $inputFilter->lastMethod);
		self::assertSame('none', $request->getPost('absent', default: 'none'));
		self::assertSame(['absent', 'alphanohtml'], $inputFilter->lastCall);
	}

	public function testGetPostWithoutFilterThrows(): void
	{
		$this->expectException(\LogicException::class);
		(new Request('POST', '/', [], [], [], []))->getPost('x');
	}

	public function testGetDefaultsToAlphanohtmlAndReturnsTheDefaultWhenMissing(): void
	{
		$inputFilter = new FakeInputFilter([]);
		self::assertSame('none', (new Request('GET', '/', [], [], [], []))->withInputFilter($inputFilter)->get('x', default: 'none'));
		self::assertSame(['x', 'alphanohtml'], $inputFilter->lastCall);
	}

	public function testGetIntUsesTheIntFilterAndCasts(): void
	{
		$inputFilter = new FakeInputFilter(['id' => '12']);
		$request = (new Request('GET', '/', [], [], [], []))->withInputFilter($inputFilter);
		self::assertSame(12, $request->getInt('id'));
		self::assertSame(['id', 'int'], $inputFilter->lastCall);
		self::assertSame(7, $request->getInt('missing', 7));
	}

	public function testIsSetDelegatesToInjectedFilter(): void
	{
		$request = (new Request('GET', '/', [], [], [], []))->withInputFilter(new FakeInputFilter(['a' => '']));
		self::assertTrue($request->isSet('a'));
		self::assertFalse($request->isSet('b'));
	}

	public function testWithInputFilterReturnsANewInstance(): void
	{
		$request = new Request('GET', '/user/1', ['q' => 'v'], [], [], []);
		$filtered = $request->withInputFilter(new FakeInputFilter([]));
		self::assertNotSame($request, $filtered);
		self::assertSame('/user/1', $filtered->path());
		self::assertSame('v', $filtered->query('q'));
		$this->expectException(\LogicException::class);
		$request->get('q');
	}
}
