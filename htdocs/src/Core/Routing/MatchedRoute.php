<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

/**
 * Result of a successful route match.
 */
final class MatchedRoute
{
	/**
	 * @param array<string,string> $params Path variables exactly as captured
	 */
	public function __construct(
		public readonly RouteDefinition $route,
		public readonly array $params
	) {
	}
}
