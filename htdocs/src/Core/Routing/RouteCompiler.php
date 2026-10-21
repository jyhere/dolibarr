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
	 * Constants a route may declare (#[Route(constants: ...)]), defined before main.inc.php. Those that lift a
	 * security check (NOLOGIN, NOCSRFCHECK, NOIPCHECK...) are refused: a public route uses public: true.
	 */
	private const ALLOWED_CONSTANTS = ['EVEN_IF_ONLY_LOGIN_ALLOWED', 'NOREDIRECTBYMAINTOLOGIN', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX', 'NOTOKENRENEWAL'];

	/**
	 * @param list<class-string> $controllers
	 * @return list<RouteDefinition>
	 * @throws \LogicException When two routes have the same name, or a route declares a constant outside ALLOWED_CONSTANTS
	 */
	public function compile(array $controllers): array
	{
		$definitions = [];
		/** @var array<string,string> $declaredBy route name => Class::method() */
		$declaredBy = [];
		foreach ($controllers as $controller) {
			$class = new ReflectionClass($controller);
			foreach ($class->getMethods() as $method) {
				foreach ($method->getAttributes(Route::class) as $attribute) {
					$route = $attribute->newInstance();
					$name = $route->name !== '' ? $route->name : $this->defaultName($class->getShortName(), $method->getName());
					$where = $controller.'::'.$method->getName().'()';
					if (isset($declaredBy[$name])) {
						throw new \LogicException('Route name "'.$name.'" is declared twice: by '.$declaredBy[$name].' and by '.$where);
					}
					$declaredBy[$name] = $where;
					foreach ($route->constants as $constant) {
						if (!in_array($constant, self::ALLOWED_CONSTANTS, true)) {
							throw new \LogicException('Route constant "'.$constant.'" declared by '.$where.' is not allowed (RouteCompiler::ALLOWED_CONSTANTS)');
						}
					}
					[$regex, $variables] = $this->compilePath($route->path, $route->requirements);
					$definitions[] = new RouteDefinition(
						$name,
						$route->path,
						array_values(array_unique(array_map('strtoupper', $route->methods))),
						$controller,
						$method->getName(),
						$route->requirements,
						$route->public,
						$regex,
						$variables,
						array_values(array_unique($route->constants))
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
