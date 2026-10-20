<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

/**
 * Matches a method and path against route definitions.
 */
final class RouteMatcher
{
	/** @var array<string,list<RouteDefinition>> static routes indexed by path */
	private array $static = [];

	/** @var list<RouteDefinition> */
	private array $dynamic = [];

	/**
	 * @param list<RouteDefinition> $definitions
	 */
	public function __construct(array $definitions)
	{
		foreach ($definitions as $definition) {
			if ($definition->regex === null) {
				$this->static[$definition->path][] = $definition;
			} else {
				$this->dynamic[] = $definition;
			}
		}
	}

	/**
	 * HEAD is answered by an explicit HEAD route, else by the GET route of the path.
	 *
	 * @throws RouteNotFoundException
	 * @throws MethodNotAllowedException
	 */
	public function match(string $method, string $path): MatchedRoute
	{
		$method = strtoupper($method);
		if ($method === 'HEAD') {
			try {
				return $this->matchMethod('HEAD', $path);
			} catch (MethodNotAllowedException) {
				$method = 'GET';
			}
		}

		return $this->matchMethod($method, $path);
	}

	/**
	 * @throws RouteNotFoundException
	 * @throws MethodNotAllowedException
	 */
	private function matchMethod(string $method, string $path): MatchedRoute
	{
		/** @var list<string> $allowed */
		$allowed = [];

		foreach ($this->static[$path] ?? [] as $definition) {
			if (in_array($method, $definition->methods, true)) {
				return new MatchedRoute($definition, []);
			}
			$allowed = array_merge($allowed, $definition->methods);
		}

		foreach ($this->dynamic as $definition) {
			if ($definition->regex === null || preg_match($definition->regex, $path, $matches) !== 1) {
				continue;
			}
			if (in_array($method, $definition->methods, true)) {
				$params = [];
				foreach ($definition->variables as $variable) {
					$params[$variable] = (string) ($matches[$variable] ?? '');
				}

				return new MatchedRoute($definition, $params);
			}
			$allowed = array_merge($allowed, $definition->methods);
		}

		if ($allowed !== []) {
			throw new MethodNotAllowedException(array_values(array_unique($allowed)));
		}

		throw new RouteNotFoundException('No route matches '.$path);
	}
}
