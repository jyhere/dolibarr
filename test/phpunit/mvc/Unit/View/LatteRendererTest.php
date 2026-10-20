<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\View;

use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Core\View\LatteRenderer;
use Dolibarr\Tests\Unit\Routing\Fixture\FakeController;
use Dolibarr\Tests\Unit\View\Fixture\FakeCurrentUser;
use Dolibarr\Tests\Unit\View\Fixture\FakeTranslator;
use PHPUnit\Framework\TestCase;

final class LatteRendererTest extends TestCase
{
	private function renderer(): LatteRenderer
	{
		$urls = new UrlGenerator((new RouteCompiler())->compile([FakeController::class]), '/d');
		$ext = new DolibarrExtension(new FakeTranslator(), new FakeCurrentUser(), $urls);

		return new LatteRenderer(
			sys_get_temp_dir().'/latte-test-'.getmypid(),
			['core' => dirname(__DIR__, 5).'/htdocs/src/Core/templates', 'test' => __DIR__.'/Fixture'],
			$ext,
			true
		);
	}

	public function testRendersWithAutoEscapeAndTransFilter(): void
	{
		$html = $this->renderer()->render('test:hello', ['name' => '<x>']);
		self::assertStringContainsString('&lt;x&gt;', $html);
		self::assertStringContainsString('<b>TR:Hello</b>', $html);
	}

	public function testAddGlobalIsVisibleAndParamsOverride(): void
	{
		$r = $this->renderer();
		$r->addGlobal('site', 'S');
		$r->addGlobal('who', 'global');
		self::assertSame('S-global', trim($r->render('test:globals', [])));
		self::assertSame('S-param', trim($r->render('test:globals', ['who' => 'param'])));
	}

	public function testHasRightAndLegacyHookDefault(): void
	{
		self::assertSame('RW[]', trim($this->renderer()->render('test:functions', [])));
	}

	public function testLegacyRefusesNonWhitelistedFunction(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->renderer()->render('test:legacy', []);
	}

	public function testUrlFilter(): void
	{
		self::assertSame('/d/user/7', trim($this->renderer()->render('test:url', [])));
	}

	public function testLayoutAcrossSlugs(): void
	{
		$html = $this->renderer()->render('test:child', []);
		self::assertStringContainsString('<!DOCTYPE html>', $html);
		self::assertStringContainsString('<body><i>child</i>', str_replace(["\n", "\t"], '', $html));
	}

	public function testUnknownSlugThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->renderer()->render('nope:hello', []);
	}
}
