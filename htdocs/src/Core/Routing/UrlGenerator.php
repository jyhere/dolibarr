<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

/**
 * Builds URLs from route names.
 */
final class UrlGenerator
{
	/** @var array<string,RouteDefinition> */
	private array $byName = [];

	/**
	 * @param list<RouteDefinition> $definitions
	 */
	public function __construct(array $definitions, private readonly string $urlRoot)
	{
		foreach ($definitions as $definition) {
			$this->byName[$definition->name] = $definition;
		}
	}

	/**
	 * @param array<string,scalar> $params Path variables, remaining ones go to the query string
	 * @throws \InvalidArgumentException
	 */
	public function generate(string $name, array $params = []): string
	{
		if (!isset($this->byName[$name])) {
			throw new \InvalidArgumentException('Unknown route "'.$name.'"');
		}
		$route = $this->byName[$name];

		$path = $route->path;
		foreach ($route->variables as $variable) {
			if (!array_key_exists($variable, $params)) {
				throw new \InvalidArgumentException('Missing parameter "'.$variable.'" for route "'.$name.'"');
			}
			$path = str_replace('{'.$variable.'}', rawurlencode((string) $params[$variable]), $path);
			unset($params[$variable]);
		}

		$url = $this->urlRoot.$path;
		if ($params !== []) {
			$url .= '?'.http_build_query($params);
		}

		return $url;
	}
}
