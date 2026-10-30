<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

/**
 * Thumbnails (_small and _mini, in the "thumbs" subdirectory) of the attached images.
 */
final class Thumbnails
{
	private const IMAGE_EXTENSION_PATTERN = '/(\.jpg|\.jpeg|\.bmp|\.gif|\.png|\.tiff|\.webp|\.xpm|\.xbm|\.avif)$/i';

	public function __construct(private readonly FileStorage $storage)
	{
	}

	/**
	 * Create the thumbnails of a freshly uploaded image (nothing for other files).
	 */
	public function createForUpload(string $path): void
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';

		if (\image_format_supported($path) !== 1 || !$this->thumbsDirectoryIsSafe(dirname($path))) {
			return;
		}

		$sizes = \getDefaultImageSizes();
		$quality = 50; // For thumbs, the quality is forced to 50
		\vignette($path, $sizes['maxwidthsmall'], $sizes['maxheightsmall'], '_small', $quality, 'thumbs');
		\vignette($path, $sizes['maxwidthmini'], $sizes['maxheightmini'], '_mini', $quality, 'thumbs');
	}

	/**
	 * Delete the thumbnails of a deleted image.
	 */
	public function deleteFor(string $path, ?\CommonObject $pageObject): void
	{
		$matches = [];
		if (preg_match(self::IMAGE_EXTENSION_PATTERN, $path, $matches) !== 1) {
			return;
		}
		$directory = dirname($path);
		if (!$this->thumbsDirectoryIsSafe($directory)) {
			return;
		}

		$extension = $matches[0];
		$baseName = basename(substr($path, 0, -strlen($extension)));
		foreach (['_small', '_mini'] as $suffix) {
			$thumbnailPath = $directory.'/thumbs/'.$baseName.$suffix.$extension;
			if (file_exists(\dol_osencode($thumbnailPath))) {
				$this->storage->delete($thumbnailPath, $pageObject);
			}
		}
	}

	/**
	 * After a rename: thumbnails of the new name, then removal of those of the old name.
	 */
	public function regenerate(\CommonObject $legacyObject, string $sourcePath, string $targetPath): void
	{
		$legacyObject->addThumbs($targetPath);
		$legacyObject->delThumbs($sourcePath);
	}

	/**
	 * The thumbs directory must not be a symbolic link, nor resolve outside of the documents root.
	 */
	private function thumbsDirectoryIsSafe(string $directory): bool
	{
		$thumbsDirectory = $directory.'/thumbs';
		if (is_link($thumbsDirectory)) {
			return false;
		}
		$realThumbs = realpath($thumbsDirectory);
		if ($realThumbs === false) {
			return true;
		}
		$realRoot = realpath(DOL_DATA_ROOT);

		return $realRoot !== false && str_starts_with($realThumbs, rtrim($realRoot, '/').'/');
	}
}
