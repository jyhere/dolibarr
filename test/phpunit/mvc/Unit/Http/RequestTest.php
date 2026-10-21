<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Http;

use Dolibarr\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
	public function testPathStripsQueryTrailingSlashAndDoubleSlashes(): void
	{
		$request = new Request('GET', '//user/1/?x=1', [], [], [], []);
		self::assertSame('/user/1', $request->path());
	}

	public function testRootPathStaysSlash(): void
	{
		self::assertSame('/', (new Request('GET', '/', [], [], [], []))->path());
	}

	public function testUrlRootPrefixIsRemoved(): void
	{
		$_SERVER['REQUEST_URI'] = '/dolibarr/user/1';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertSame('/user/1', Request::fromGlobals('/dolibarr')->path());
	}

	public function testWantsJsonFromAcceptHeaderWithCharset(): void
	{
		self::assertTrue((new Request('GET', '/user/1', [], [], ['Accept' => 'application/json;charset=utf-8'], []))->wantsJson());
	}

	public function testStarSlashStarAndBrowserAcceptAreHtml(): void
	{
		self::assertFalse((new Request('GET', '/u', [], [], ['Accept' => '*/*'], []))->wantsJson());
		self::assertFalse((new Request('GET', '/u', [], [], ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'], []))->wantsJson());
	}

	public function testJsonSuffixAndFormatParam(): void
	{
		$request = new Request('GET', '/user/1.json', [], [], [], []);
		self::assertTrue($request->wantsJson());
		self::assertSame('/user/1', $request->path());
		self::assertTrue((new Request('GET', '/user/1', ['format' => 'json'], [], [], []))->wantsJson());
	}
}
