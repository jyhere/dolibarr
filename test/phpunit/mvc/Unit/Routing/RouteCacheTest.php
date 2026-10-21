<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing;

use Dolibarr\Core\Routing\RouteCache;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Tests\Unit\Routing\Fixture\FakeController;
use PHPUnit\Framework\TestCase;

final class RouteCacheTest extends TestCase
{
	public function testCacheRoundTripAndFreshness(): void
	{
		$defs = (new RouteCompiler())->compile([FakeController::class]);
		$base = sys_get_temp_dir().'/routes_'.uniqid();
		$file = $base.'.php';
		$controller = $base.'_controller.php';
		$cache = new RouteCache($file);
		try {
			self::assertNull($cache->load('s'));
			self::assertFalse($cache->isFresh([]));
			$cache->store($defs, 's');
			self::assertEquals($defs, $cache->load('s'));

			$timestamp = time() - 1000;
			file_put_contents($controller, '<?php');
			touch($file, $timestamp);

			touch($controller, $timestamp - 10);
			self::assertTrue($cache->isFresh([$controller]));

			touch($controller, $timestamp + 10);
			self::assertFalse($cache->isFresh([$controller]));

			unlink($controller);
			self::assertFalse($cache->isFresh([$controller]));
		} finally {
			@unlink($file);
			@unlink($controller);
		}
	}

	public function testStoreThrowsWhenCacheDirectoryCannotBeCreated(): void
	{
		$notADir = tempnam(sys_get_temp_dir(), 'routes-file');
		self::assertIsString($notADir);
		try {
			$this->expectException(\RuntimeException::class);
			(new RouteCache($notADir.'/cache/routes.php'))->store([], 's');
		} finally {
			unlink($notADir);
		}
	}
}
