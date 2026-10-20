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
			self::assertNull($cache->load());
			self::assertFalse($cache->isFresh([]));
			$cache->store($defs);
			self::assertEquals($defs, $cache->load());

			$t = time() - 1000;
			file_put_contents($controller, '<?php');
			touch($file, $t);

			touch($controller, $t - 10);
			self::assertTrue($cache->isFresh([$controller]));

			touch($controller, $t + 10);
			self::assertFalse($cache->isFresh([$controller]));

			unlink($controller);
			self::assertFalse($cache->isFresh([$controller]));
		} finally {
			@unlink($file);
			@unlink($controller);
		}
	}
}
