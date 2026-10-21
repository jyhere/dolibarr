<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

/**
 * Compiled route, serializable to a plain array for caching.
 *
 * @phpstan-type RouteArray array{name: string, path: string, methods: list<string>, controller: class-string, action: string, requirements: array<string,string>, public: bool, regex: ?string, variables: list<string>, constants?: list<string>}
 */
final class RouteDefinition
{
	/**
	 * @param list<string>         $methods
	 * @param class-string         $controller
	 * @param array<string,string> $requirements
	 * @param list<string>         $variables
	 * @param list<string>         $constants Constants to define before main.inc.php (Route::$constants)
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $path,
		public readonly array $methods,
		public readonly string $controller,
		public readonly string $action,
		public readonly array $requirements,
		public readonly bool $public,
		public readonly ?string $regex,
		public readonly array $variables,
		public readonly array $constants = []
	) {
	}

	/**
	 * @return RouteArray
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'path' => $this->path,
			'methods' => $this->methods,
			'controller' => $this->controller,
			'action' => $this->action,
			'requirements' => $this->requirements,
			'public' => $this->public,
			'regex' => $this->regex,
			'variables' => $this->variables,
			'constants' => $this->constants,
		];
	}

	/**
	 * @param RouteArray $a A cache written before 'constants' existed has none
	 */
	public static function fromArray(array $a): self
	{
		return new self(
			$a['name'],
			$a['path'],
			$a['methods'],
			$a['controller'],
			$a['action'],
			$a['requirements'],
			$a['public'],
			$a['regex'],
			$a['variables'],
			$a['constants'] ?? []
		);
	}
}
