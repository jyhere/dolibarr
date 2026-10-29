<?php

declare(strict_types=1);

namespace Dolibarr\Core\Http;

/**
 * One entry of $_FILES, as PHP reports it.
 */
final class UploadedFile
{
	public function __construct(
		public readonly string $name,
		public readonly string $tmpName,
		public readonly string $type,
		public readonly int $size,
		public readonly int $error,
	) {
	}
}
