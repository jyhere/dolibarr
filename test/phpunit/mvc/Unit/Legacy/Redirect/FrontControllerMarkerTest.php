<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Legacy\Redirect;

use Dolibarr\Core\Legacy\Redirect\FrontControllerMarker;
use PHPUnit\Framework\TestCase;

final class FrontControllerMarkerTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir().'/mvc-marker-'.bin2hex(random_bytes(4));
	}

	protected function tearDown(): void
	{
		@unlink($this->dir.'/cache/mvc_front_ok');
		@rmdir($this->dir.'/cache');
		if (is_file($this->dir)) {
			unlink($this->dir);
		}
		@rmdir($this->dir);
	}

	public function testAbsentUntilMarkedThenPresentAndEmpty(): void
	{
		$marker = new FrontControllerMarker($this->dir);
		self::assertSame($this->dir.'/cache/mvc_front_ok', $marker->file());
		self::assertFalse($marker->exists());

		$marker->mark();

		self::assertTrue($marker->exists());
		self::assertSame('', (string) file_get_contents($marker->file()));
	}

	public function testMarkKeepsAnExistingMarker(): void
	{
		$marker = new FrontControllerMarker($this->dir);
		$marker->mark();
		touch($marker->file(), 1000000000);
		clearstatcache();

		$marker->mark();

		clearstatcache();
		self::assertSame(1000000000, filemtime($marker->file()));
	}

	public function testUnwritableDataRootNeverThrows(): void
	{
		file_put_contents($this->dir, '');
		$marker = new FrontControllerMarker($this->dir);

		$marker->mark();

		self::assertFalse($marker->exists());
	}
}
