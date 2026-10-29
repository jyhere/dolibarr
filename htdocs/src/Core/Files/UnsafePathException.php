<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

/**
 * A file name or directory that would reach outside the documents directory.
 */
final class UnsafePathException extends \RuntimeException
{
}
