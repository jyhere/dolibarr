<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration;

use Dolibarr\Core\Http\Response;
use Dolibarr\Core\Kernel;
use Dolibarr\Tests\Integration\Fixture\ThrowingController;
use Dolibarr\Tests\Integration\Fixture\ThrowingModule;
use PHPUnit\Framework\TestCase;

final class KernelRunTest extends TestCase
{
	private string $dataRoot;

	/** @var array<mixed> */
	private array $server;

	protected function setUp(): void
	{
		$this->server = $_SERVER;
		$this->dataRoot = sys_get_temp_dir().'/kernel-run-'.uniqid();
	}

	protected function tearDown(): void
	{
		$_SERVER = $this->server;
		if (is_file($this->dataRoot)) {
			unlink($this->dataRoot);
		} elseif (is_dir($this->dataRoot)) {
			exec('rm -rf '.escapeshellarg($this->dataRoot));
		}
	}

	public function testUncaughtExceptionIsAJson500WithoutDetails(): void
	{
		$response = $this->runKernel('GET', '/fixture/boom', true);

		self::assertSame(500, $response->status());
		self::assertSame('{"error":"server_error"}', $response->body());
	}

	public function testUncaughtExceptionIsAnHtml500WithoutDetails(): void
	{
		$response = $this->runKernel('GET', '/fixture/boom', false);

		self::assertSame(500, $response->status());
		self::assertStringContainsString($GLOBALS['langs']->trans('ErrorInternalErrorDetected'), $response->body());
		self::assertStringNotContainsString(ThrowingController::SECRET, $response->body());
		self::assertStringContainsString('text/html', $response->headers()['Content-Type']);
	}

	public function testHtml500FallsBackToPlainTextWhenTheTemplateCannotBeRendered(): void
	{
		// The core namespace pointed to a directory without error500.latte: the error page itself fails.
		$log = tempnam(sys_get_temp_dir(), 'kernel-log');
		self::assertIsString($log);
		$previous = ini_set('error_log', $log);
		try {
			$response = $this->runKernel('GET', '/fixture/boom', false, ['core' => sys_get_temp_dir()]);
			self::assertStringContainsString('error page not rendered', (string) file_get_contents($log));
		} finally {
			ini_set('error_log', $previous === false ? '' : $previous);
			unlink($log);
		}

		self::assertSame(500, $response->status());
		self::assertSame('Internal Server Error', $response->body());
		self::assertStringStartsWith('text/plain', $response->headers()['Content-Type']);
	}

	public function testAccessDeniedIsAJson403(): void
	{
		$response = $this->runKernel('GET', '/fixture/denied', true);

		self::assertSame(403, $response->status());
		self::assertSame('{"error":"forbidden"}', $response->body());
	}

	public function testMethodNotAllowedIsAJson405WithAllow(): void
	{
		$response = $this->runKernel('POST', '/fixture/get-only', true);

		self::assertSame(405, $response->status());
		self::assertSame('{"error":"method_not_allowed"}', $response->body());
		self::assertSame('GET', $response->headers()['Allow']);
	}

	public function testMethodNotAllowedIsAMinimalHtml405WithAllow(): void
	{
		$response = $this->runKernel('POST', '/fixture/get-only', false);

		self::assertSame(405, $response->status());
		self::assertSame('GET', $response->headers()['Allow']);
		self::assertStringContainsString('405', $response->body());
	}

	/**
	 * @param array<string, string> $templateDirs
	 */
	private function runKernel(string $method, string $uri, bool $json, array $templateDirs = []): Response
	{
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI'] = $uri;
		if ($json) {
			$_SERVER['HTTP_ACCEPT'] = 'application/json';
		} else {
			unset($_SERVER['HTTP_ACCEPT']);
		}
		$kernel = new Kernel(DOL_DOCUMENT_ROOT, $this->dataRoot, '', false, DOL_VERSION, [new ThrowingModule($templateDirs)]);

		return $kernel->run($kernel->match(), 1);
	}
}
