<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

use Attribute;

/**
 * Declares an HTTP route on a controller method.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
	/**
	 * @param string               $path         Path, variables as {name}
	 * @param list<string>         $methods      Allowed HTTP methods
	 * @param string               $name         Route name (derived from controller and action when empty)
	 * @param array<string,string> $requirements Regex fragment per variable
	 * @param bool                 $public       True when no authentication is required
	 */
	public function __construct(
		public string $path,
		public array $methods = ['GET'],
		public string $name = '',
		public array $requirements = [],
		public bool $public = false
	) {
	}
}
