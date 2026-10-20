<?php

declare(strict_types=1);

namespace Dolibarr\Core\Security;

/**
 * The current user may not access the requested resource (HTTP 403).
 *
 * The message, when not empty, is a translation key given to accessforbidden().
 */
final class AccessDeniedException extends \RuntimeException
{
}
