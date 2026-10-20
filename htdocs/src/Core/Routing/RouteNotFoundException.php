<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

/**
 * No route matches the requested path (HTTP 404).
 */
final class RouteNotFoundException extends \RuntimeException
{
}
