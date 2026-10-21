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

	public function testJsonContainsOnlyTheExposedData(): void
	{
		$vm = (new ViewModel('test:hello', ['title' => 'T', 'form' => new \stdClass(), 'hookHtml' => '<b>x</b>']))
			->expose('user', new ExposableStub(['id' => 1]))
			->expose('total', 3);
		$resp = $this->responder(true)->respond($vm);
		self::assertInstanceOf(JsonResponse::class, $resp);
		self::assertSame('{"user":{"id":1},"total":3}', $resp->body());
	}

	public function testJsonWithoutExposedDataIsAnEmptyObject(): void
	{
		$resp = $this->responder(true)->respond(new ViewModel('test:hello', ['title' => 'T']));
		self::assertSame('{}', $resp->body());
	}

	public function testNestedExposablesAreConvertedRecursively(): void
	{
		$vm = (new ViewModel('test:hello'))->expose('items', [
			new ExposableStub(['id' => 1, 'tags' => ['a', null, 2.5]]),
			['inner' => new ExposableStub(['id' => 2, 'child' => new ExposableStub(['ok' => true])])],
		]);
		$resp = $this->responder(true)->respond($vm);
		self::assertSame('{"items":[{"id":1,"tags":["a",null,2.5]},{"inner":{"id":2,"child":{"ok":true}}}]}', $resp->body());
	}

	public function testNestedPlainObjectThrows(): void
	{
		$vm = (new ViewModel('test:hello'))->expose('items', [['user' => new \stdClass()]]);
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('items.0.user');
		$this->responder(true)->respond($vm);
	}

	public function testPlainObjectInsideAnExposableThrows(): void
	{
		$vm = (new ViewModel('test:hello'))->expose('user', new ExposableStub(['legacy' => new \stdClass()]));
		$this->expectException(\LogicException::class);
		$this->responder(true)->respond($vm);
	}

	public function testExposedDataIsAlsoAvailableToTheTemplate(): void
	{
		$resp = $this->responder(false)->respond((new ViewModel('test:hello'))->expose('name', 'a'));
		self::assertStringContainsString('<b>TR:Hello</b>', $resp->body());
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
		$original = new ViewModel('t', ['a' => 1]);
		$extended = $original->with('b', 2);
		self::assertSame(['a' => 1], $original->data);
		self::assertSame(['a' => 1, 'b' => 2], $extended->data);
		$exposedModel = $extended->expose('e', 3);
		self::assertSame([], $extended->exposed);
		self::assertSame(['e' => 3], $exposedModel->exposed);
		self::assertSame(['e' => 3], $exposedModel->with('d', 4)->exposed);
		self::assertSame(404, (new ViewModel('t', [], 404))->expose('e', 1)->status);
	}
}
