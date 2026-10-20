<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing;

use Dolibarr\Core\Routing\RouteCache;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Tests\Unit\Routing\Fixture\FakeController;
use PHPUnit\Framework\TestCase;

final class RouteCacheStampTest extends TestCase
{
	public function testStaleStampForcesRecompile(): void
	{
		$defs = (new RouteCompiler())->compile([FakeController::class]);
		$file = sys_get_temp_dir().'/routes_stamp_'.uniqid().'.php';
		try {
			$cache = new RouteCache($file);
			$cache->store($defs, '1.0|abc');

			self::assertEquals($defs, $cache->load('1.0|abc'));
			self::assertNull($cache->load('2.0|abc'));
			self::assertNull($cache->load('1.0|def'));
		} finally {
			@unlink($file);
		}
	}

	public function testMalformedCacheIsIgnored(): void
	{
		$file = sys_get_temp_dir().'/routes_stamp_'.uniqid().'.php';
		try {
			file_put_contents($file, '<?php return [["path" => "/x"]];');
			self::assertNull((new RouteCache($file))->load('s'));
		} finally {
			@unlink($file);
		}
	}
}
