<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

/**
 * Production mover: move_uploaded_file() only accepts files PHP received through an HTTP upload.
 */
final class PhpUploadedFileMover implements UploadedFileMover
{
	public function move(string $tmpName, string $destination): bool
	{
		return move_uploaded_file($tmpName, $destination);
	}
}
