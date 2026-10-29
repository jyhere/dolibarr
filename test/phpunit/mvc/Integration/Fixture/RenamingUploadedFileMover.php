<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Fixture;

use Dolibarr\Core\Files\UploadedFileMover;

/**
 * Moves with rename(): move_uploaded_file() refuses files that were not uploaded through HTTP, as in the CLI.
 */
final class RenamingUploadedFileMover implements UploadedFileMover
{
	public function move(string $tmpName, string $destination): bool
	{
		return rename($tmpName, $destination);
	}
}
