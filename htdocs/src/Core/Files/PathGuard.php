<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

/**
 * Builds paths of files inside one documents directory and refuses everything that could leave it.
 */
final class PathGuard
{
	public function __construct(private readonly string $directory)
	{
	}

	/**
	 * Absolute path of a file directly inside the directory.
	 *
	 * @throws UnsafePathException
	 */
	public function inside(string $fileName): string
	{
		if (
			$fileName === ''
			|| preg_match('#[/\\\\<>|\0]#', $fileName) === 1
			|| str_contains($fileName, '..')
			|| str_starts_with($fileName, '.')
		) {
			throw new UnsafePathException('Unsafe file name');
		}

		$realDirectory = realpath($this->directory);
		if ($realDirectory !== false) {
			$realRoot = realpath(DOL_DATA_ROOT);
			if ($realRoot === false || ($realDirectory !== $realRoot && !str_starts_with($realDirectory, rtrim($realRoot, '/').'/'))) {
				throw new UnsafePathException('Directory outside of the documents root');
			}
		}

		$path = $this->directory.'/'.$fileName;
		if (is_link($path)) {
			throw new UnsafePathException('Symbolic link refused');
		}

		return $path;
	}
}
