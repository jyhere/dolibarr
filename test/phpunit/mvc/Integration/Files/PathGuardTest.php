<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Files;

use Dolibarr\Core\Files\PathGuard;
use Dolibarr\Core\Files\UnsafePathException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
	private string $directory;

	protected function setUp(): void
	{
		$this->directory = DOL_DATA_ROOT.'/pathguard-test-'.bin2hex(random_bytes(4));
		mkdir($this->directory, 0777, true);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->directory.'/{*,.[!.]*}', GLOB_BRACE) ?: [] as $entry) {
			unlink($entry);
		}
		rmdir($this->directory);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsafeNames(): array
	{
		return [
			'traversal' => ['../x'],
			'slash' => ['a/b'],
			'backslash' => ['a\\b'],
			'hidden' => ['.htaccess'],
			'empty' => [''],
			'nul' => ["a\0b"],
			'pipe' => ['a|b'],
			'lower than' => ['a<b'],
			'greater than' => ['a>b'],
			'double dots inside' => ['a..b'],
		];
	}

	#[DataProvider('unsafeNames')]
	public function testRefusesTraversalAndSpecialNames(string $fileName): void
	{
		$this->expectException(UnsafePathException::class);

		(new PathGuard($this->directory))->inside($fileName);
	}

	public function testAcceptsAPlainName(): void
	{
		self::assertSame($this->directory.'/facture 1.pdf', (new PathGuard($this->directory))->inside('facture 1.pdf'));
	}

	public function testRefusesASymlinkTarget(): void
	{
		symlink('/etc/hosts', $this->directory.'/link.txt');

		$this->expectException(UnsafePathException::class);

		(new PathGuard($this->directory))->inside('link.txt');
	}

	public function testRefusesADirectoryOutsideTheDataRoot(): void
	{
		$this->expectException(UnsafePathException::class);

		(new PathGuard('/tmp'))->inside('x.txt');
	}

	public function testThumbsPath(): void
	{
		self::assertSame(
			$this->directory.'/thumbs/photo_small.jpg',
			(new PathGuard($this->directory))->thumbsPath('photo.jpg', '_small')
		);
	}
}
