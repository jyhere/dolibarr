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
		$file = sys_get_temp_dir().'/routes_'.uniqid().'.php';
		$cache = new RouteCache($file);
		try {
			self::assertNull($cache->load());
			self::assertFalse($cache->isFresh([__FILE__]));
			$cache->store($defs);
			$loaded = $cache->load();
			self::assertNotNull($loaded);
			self::assertEquals($defs, $loaded);
			self::assertTrue($cache->isFresh([__FILE__]));
			touch($file, time() - 100);
			self::assertFalse($cache->isFresh([__FILE__]));
		} finally {
			@unlink($file);
		}
	}
}
