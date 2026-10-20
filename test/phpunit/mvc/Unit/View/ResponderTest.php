<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\View;

use Dolibarr\Core\Http\HtmlResponse;
use Dolibarr\Core\Http\JsonResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Core\View\LatteRenderer;
use Dolibarr\Core\View\Responder;
use Dolibarr\Core\View\ViewModel;
use Dolibarr\Tests\Unit\View\Fixture\ExposableStub;
use Dolibarr\Tests\Unit\View\Fixture\FakeCurrentUser;
use Dolibarr\Tests\Unit\View\Fixture\FakeTranslator;
use PHPUnit\Framework\TestCase;

final class ResponderTest extends TestCase
{
	private function responder(bool $wantsJson): Responder
	{
		$ext = new DolibarrExtension(new FakeTranslator(), new FakeCurrentUser(), new UrlGenerator([], ''));
		$renderer = new LatteRenderer(
			sys_get_temp_dir().'/latte-test-'.getmypid(),
			['core' => dirname(__DIR__, 5).'/htdocs/src/Core/templates', 'test' => __DIR__.'/Fixture'],
			$ext,
			true
		);
		$request = new Request('GET', '/x', [], [], $wantsJson ? ['Accept' => 'application/json'] : [], []);

		return new Responder($renderer, $request);
	}

	public function testResponderReturnsJsonForJsonRequestsAndSkipsNonExposableObjects(): void
	{
		$vm = new ViewModel('test:hello', ['user' => new ExposableStub(['id' => 1]), 'form' => new \stdClass(), 'total' => 3]);
		$resp = $this->responder(true)->respond($vm);
		self::assertInstanceOf(JsonResponse::class, $resp);
		self::assertSame('{"user":{"id":1},"total":3}', $resp->body());
	}

	public function testResponderReturnsHtmlOtherwiseWithViewModelStatus(): void
	{
		$resp = $this->responder(false)->respond(new ViewModel('test:hello', ['name' => 'a'], 404));
		self::assertInstanceOf(HtmlResponse::class, $resp);
		self::assertSame(404, $resp->status());
		self::assertStringContainsString('<b>TR:Hello</b>', $resp->body());
	}

	public function testViewModelWithIsImmutable(): void
	{
		$a = new ViewModel('t', ['a' => 1]);
		$b = $a->with('b', 2);
		self::assertSame(['a' => 1], $a->data);
		self::assertSame(['a' => 1, 'b' => 2], $b->data);
	}
}
