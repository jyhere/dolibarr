<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

/**
 * Moves an uploaded temporary file to its destination.
 */
interface UploadedFileMover
{
	public function move(string $tmpName, string $destination): bool;
}
