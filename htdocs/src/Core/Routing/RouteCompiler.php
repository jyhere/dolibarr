<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

use ReflectionClass;

/**
 * Builds route definitions from #[Route] attributes using reflection.
 */
final class RouteCompiler
{
	private const DEFAULT_REQUIREMENT = '[^/]+';

	/**
	 * @param list<class-string> $controllers
	 * @return list<RouteDefinition>
	 */
	public function compile(array $controllers): array
	{
		$definitions = [];
		foreach ($controllers as $controller) {
			$class = new ReflectionClass($controller);
			foreach ($class->getMethods() as $method) {
				foreach ($method->getAttributes(Route::class) as $attribute) {
					$route = $attribute->newInstance();
					$name = $route->name !== '' ? $route->name : $this->defaultName($class->getShortName(), $method->getName());
					[$regex, $variables] = $this->compilePath($route->path, $route->requirements);
					$definitions[] = new RouteDefinition(
						$name,
						$route->path,
						$route->methods,
						$controller,
						$method->getName(),
						$route->requirements,
						$route->public,
						$regex,
						$variables
					);
				}
			}
		}

		return $definitions;
	}

	private function defaultName(string $shortClass, string $action): string
	{
		if (str_ends_with($shortClass, 'Controller') && $shortClass !== 'Controller') {
			$shortClass = substr($shortClass, 0, -strlen('Controller'));
		}
		$snake = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $shortClass));

		return $snake.'_'.$action;
	}

	/**
	 * @param array<string,string> $requirements
	 * @return array{0: ?string, 1: list<string>}
	 */
	private function compilePath(string $path, array $requirements): array
	{
		$parts = preg_split('/\{(\w+)\}/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false || count($parts) === 1) {
			return [null, []];
		}

		$regex = '';
		$variables = [];
		foreach ($parts as $i => $part) {
			if ($i % 2 === 0) {
				$regex .= preg_quote($part, '#');
			} else {
				$variables[] = $part;
				$regex .= '(?P<'.$part.'>'.($requirements[$part] ?? self::DEFAULT_REQUIREMENT).')';
			}
		}

		return ['#^'.$regex.'$#', $variables];
	}
}
