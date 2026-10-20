<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit;

use Dolibarr\Core\BootstrapMode;
use Dolibarr\Core\Kernel;
use Dolibarr\Core\Routing\MethodNotAllowedException;
use Dolibarr\Core\Routing\RouteNotFoundException;
use Dolibarr\Tests\Unit\Fixture\FixtureController;
use Dolibarr\Tests\Unit\Fixture\FixtureModule;
use PHPUnit\Framework\TestCase;

final class KernelMatchTest extends TestCase
{
	private string $dataRoot;

	/** @var array<mixed> */
	private array $server;

	protected function setUp(): void
	{
		$this->server = $_SERVER;
		$this->dataRoot = sys_get_temp_dir().'/kernel-match-'.uniqid();
	}

	protected function tearDown(): void
	{
		$_SERVER = $this->server;
		@unlink($this->dataRoot.'/cache/routes.php');
		@rmdir($this->dataRoot.'/cache');
		@rmdir($this->dataRoot);
	}

	public function testUnknownPathIsPublicMode(): void
	{
		$result = $this->match('GET', '/d/nexistepas');

		self::assertSame(BootstrapMode::PUBLIC, $result['mode']);
		self::assertNull($result['matched']);
		self::assertSame('/nexistepas', $result['request']->path());
		self::assertInstanceOf(RouteNotFoundException::class, $result['error']);
	}

	public function testPublicRouteIsPublicMode(): void
	{
		$result = $this->match('GET', '/d/fixture/public');

		self::assertSame(BootstrapMode::PUBLIC, $result['mode']);
		self::assertNotNull($result['matched']);
		self::assertSame('publicPage', $result['matched']->route->action);
		self::assertNull($result['error']);
	}

	public function testProtectedRouteIsProtectedMode(): void
	{
		$result = $this->match('GET', '/d/fixture/42');

		self::assertSame(BootstrapMode::PROTECTED, $result['mode']);
		self::assertNotNull($result['matched']);
		self::assertSame(FixtureController::class, $result['matched']->route->controller);
		self::assertSame(['id' => '42'], $result['matched']->params);
		self::assertNull($result['error']);
	}

	public function testMethodNotAllowedIsPublicModeWithError(): void
	{
		$result = $this->match('POST', '/d/fixture/42');

		self::assertSame(BootstrapMode::PUBLIC, $result['mode']);
		self::assertNull($result['matched']);
		self::assertInstanceOf(MethodNotAllowedException::class, $result['error']);
		self::assertSame(['GET'], $result['error']->allowed());
	}

	public function testCacheFileIsWrittenInDataRoot(): void
	{
		self::assertFileDoesNotExist($this->dataRoot.'/cache/routes.php');

		$this->match('GET', '/d/fixture/public');

		self::assertFileExists($this->dataRoot.'/cache/routes.php');
	}

	public function testConstantsForEachMode(): void
	{
		self::assertSame(['NOLOGIN', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX'], BootstrapMode::constantsFor(BootstrapMode::PUBLIC));
		self::assertSame(['NOREDIRECTBYMAINTOLOGIN'], BootstrapMode::constantsFor(BootstrapMode::PROTECTED));
	}

	/**
	 * @return array{mode: string, matched: ?\Dolibarr\Core\Routing\MatchedRoute, request: \Dolibarr\Core\Http\Request, error: ?\Throwable}
	 */
	private function match(string $method, string $uri): array
	{
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI'] = $uri;
		$kernel = new Kernel('/nonexistent-document-root', $this->dataRoot, '/d', false, [new FixtureModule()]);

		return $kernel->match();
	}
}
