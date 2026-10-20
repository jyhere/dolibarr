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

	/** @var array<mixed> */
	private array $get;

	/** @var array<mixed> */
	private array $post;

	protected function setUp(): void
	{
		$this->server = $_SERVER;
		$this->get = $_GET;
		$this->post = $_POST;
		$this->dataRoot = sys_get_temp_dir().'/kernel-match-'.uniqid();
	}

	protected function tearDown(): void
	{
		$_SERVER = $this->server;
		$_GET = $this->get;
		$_POST = $this->post;
		if (is_file($this->dataRoot)) {
			unlink($this->dataRoot);
		}
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

	public function testRequestForRunReflectsGlobalsNeutralisedAfterMatch(): void
	{
		$_POST = ['token' => 'bogus', 'x' => '1'];
		$_GET = ['action' => 'delete', 'id' => '5'];
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI'] = '/d/fixture/public';
		$kernel = new Kernel('/nonexistent-document-root', $this->dataRoot, '/d', false, '1.0', [new FixtureModule()]);
		$result = $kernel->match();
		self::assertSame('1', $result['request']->post('x'));

		// What main.inc.php does on an invalid CSRF token
		unset($_POST);
		unset($_GET['action']);

		$request = $kernel->requestForRun();
		self::assertNull($request->post('x'));
		self::assertNull($request->query('action'));
		self::assertSame('5', $request->query('id'));
		self::assertSame('/fixture/public', $request->path());
	}

	public function testUnwritableCacheDirectoryStillMatchesAndLogs(): void
	{
		// A data root that is a regular file: its cache directory cannot be created.
		file_put_contents($this->dataRoot, '');
		$log = tempnam(sys_get_temp_dir(), 'kernel-log');
		self::assertIsString($log);
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->match('GET', '/d/fixture/42');
		} finally {
			ini_set('error_log', $previous === false ? '' : $previous);
		}

		self::assertSame(BootstrapMode::PROTECTED, $result['mode']);
		self::assertNotNull($result['matched']);
		self::assertStringContainsString('route cache not written', (string) file_get_contents($log));
		unlink($log);
	}

	public function testPhpSelfIsTheMatchedPathUnderTheUrlRoot(): void
	{
		$kernel = new Kernel('/nonexistent-document-root', $this->dataRoot, '/d', false, '1.0', [new FixtureModule()]);

		self::assertSame('/d/fixture/42', $kernel->phpSelfFor($this->match('GET', '/d/fixture/42?x=1')));
		self::assertSame('/d/fixture/public', $kernel->phpSelfFor($this->match('GET', '/d/fixture/public/')));
	}

	public function testPhpSelfIsNullWhenNothingMatched(): void
	{
		$kernel = new Kernel('/nonexistent-document-root', $this->dataRoot, '/d', false, '1.0', [new FixtureModule()]);

		self::assertNull($kernel->phpSelfFor($this->match('GET', '/d/nexistepas')));
		self::assertNull($kernel->phpSelfFor($this->match('POST', '/d/fixture/42')));
	}

	public function testConstantsForEachMode(): void
	{
		self::assertSame(['NOLOGIN', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX'], BootstrapMode::constantsFor(BootstrapMode::PUBLIC));
		self::assertSame(['NOREDIRECTBYMAINTOLOGIN'], BootstrapMode::constantsFor(BootstrapMode::PROTECTED));
	}

	public function testJsonSuffixIsNotStatic(): void
	{
		self::assertFalse(Kernel::isStaticAssetPath('/user/abc.json'));
		self::assertTrue(Kernel::isStaticAssetPath('/x.png'));
	}

	public function testStaleStampRecompilesTheCache(): void
	{
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = '/d/fixture/public';
		(new Kernel('/nonexistent-document-root', $this->dataRoot, '/d', true, '1.0', [new FixtureModule()]))->match();
		$file = $this->dataRoot.'/cache/routes.php';
		self::assertStringContainsString("'stamp' => '1.0|", (string) file_get_contents($file));

		(new Kernel('/nonexistent-document-root', $this->dataRoot, '/d', true, '2.0', [new FixtureModule()]))->match();

		self::assertStringContainsString("'stamp' => '2.0|", (string) file_get_contents($file));
	}

	/**
	 * @return array{mode: string, matched: ?\Dolibarr\Core\Routing\MatchedRoute, request: \Dolibarr\Core\Http\Request, error: ?\Throwable}
	 */
	private function match(string $method, string $uri): array
	{
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI'] = $uri;
		$kernel = new Kernel('/nonexistent-document-root', $this->dataRoot, '/d', false, '1.0', [new FixtureModule()]);

		return $kernel->match();
	}

	/**
	 * @return iterable<string, array{0: string, 1: bool}>
	 */
	public static function staticAssetProvider(): iterable
	{
		yield 'png' => ['/theme/eldy/img/nothere.png', true];
		yield 'uppercase JPG with query' => ['/a/B.JPG?v=1', true];
		yield 'jpeg' => ['/x.jpeg', true];
		yield 'woff2' => ['/fonts/x.woff2', true];
		yield 'js map' => ['/includes/x.min.js.map', true];
		yield 'json is not static' => ['/user/abc.json', false];
		yield 'xml is not static' => ['/x.xml', false];
		yield 'txt is not static' => ['/x.txt', false];
		yield 'encoded dot' => ['/x%2Ecss', true];
		yield 'route' => ['/user/1', false];
		yield 'php' => ['/theme/eldy/style.css.php', false];
		yield 'extension in the query only' => ['/user/1?f=a.png', false];
		yield 'no extension' => ['/nexistepas', false];
		yield 'extension as directory' => ['/x.png/y', false];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('staticAssetProvider')]
	public function testIsStaticAssetPath(string $uri, bool $expected): void
	{
		self::assertSame($expected, Kernel::isStaticAssetPath($uri));
	}
}
