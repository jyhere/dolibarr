<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing;

use Dolibarr\Core\Routing\RouteDefinitionsLoader;
use Dolibarr\Tests\Unit\Fixture\FixtureModule;
use PHPUnit\Framework\TestCase;

final class RouteDefinitionsLoaderTest extends TestCase
{
	private string $dataRoot;

	protected function setUp(): void
	{
		$this->dataRoot = sys_get_temp_dir().'/loader-'.uniqid();
	}

	protected function tearDown(): void
	{
		@unlink($this->dataRoot.'/cache/routes.php');
		@rmdir($this->dataRoot.'/cache');
		@rmdir($this->dataRoot);
	}

	public function testCompilesAndWritesTheRouteCache(): void
	{
		$loader = new RouteDefinitionsLoader($this->dataRoot, true, '1.0', [new FixtureModule()]);
		$definitions = $loader->load();

		self::assertSame(['fixture_public', 'fixture_show'], array_map(static fn ($d): string => $d->name, $definitions));
		self::assertFileExists($this->dataRoot.'/cache/routes.php');
		self::assertEquals($definitions, (new RouteDefinitionsLoader($this->dataRoot, true, '1.0', [new FixtureModule()]))->load());
	}

	public function testStampDependsOnVersionAndModules(): void
	{
		$a = new RouteDefinitionsLoader($this->dataRoot, true, '1.0', [new FixtureModule()]);
		$b = new RouteDefinitionsLoader($this->dataRoot, true, '2.0', [new FixtureModule()]);
		$c = new RouteDefinitionsLoader($this->dataRoot, true, '1.0', []);

		self::assertNotSame($a->stamp(), $b->stamp());
		self::assertNotSame($a->stamp(), $c->stamp());
	}

	public function testUnwritableCacheStillReturnsDefinitions(): void
	{
		file_put_contents($this->dataRoot, 'a file, not a directory');
		try {
			$definitions = (new RouteDefinitionsLoader($this->dataRoot, true, '1.0', [new FixtureModule()]))->load();
			self::assertCount(2, $definitions);
		} finally {
			unlink($this->dataRoot);
		}
	}
}
