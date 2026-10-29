<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

/**
 * An uploaded file once stored: its absolute path, its name on disk and whether ".noexe" was appended.
 */
final class StoredFile
{
	public function __construct(
		public readonly string $path,
		public readonly string $storedName,
		public readonly bool $noexe,
	) {
	}
}
