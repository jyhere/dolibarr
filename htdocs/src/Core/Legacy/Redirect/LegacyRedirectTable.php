<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy\Redirect;

use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Routing\RouteDefinition;

/**
 * Legacy redirect rules of all modules, indexed by script.
 *
 * @phpstan-import-type RuleArray from LegacyRedirect
 */
final class LegacyRedirectTable
{
	/** @var array<string,list<LegacyRedirect>> */
	private array $byScript = [];

	/**
	 * @param list<LegacyRedirect> $rules
	 */
	private function __construct(array $rules)
	{
		foreach ($rules as $rule) {
			$this->byScript[$rule->script][] = $rule;
		}
	}

	/**
	 * @param list<ModuleInterface> $modules
	 * @param list<RouteDefinition> $routeDefinitions
	 * @throws \LogicException When a rule names an unknown route, or maps to a name that is not a variable of that route
	 */
	public static function fromModules(array $modules, array $routeDefinitions): self
	{
		$routes = [];
		foreach ($routeDefinitions as $definition) {
			$routes[$definition->name] = $definition;
		}

		$rules = [];
		foreach ($modules as $module) {
			foreach ($module->legacyRedirects() as $rule) {
				$where = 'Module "'.$module->name().'", legacy redirect of "'.$rule->script.'" to route "'.$rule->route.'": ';
				if (!isset($routes[$rule->route])) {
					throw new \LogicException($where.'unknown route');
				}
				$variables = $routes[$rule->route]->variables;
				foreach ($rule->paramMap as $legacy => $target) {
					if (!in_array($target, $variables, true)) {
						throw new \LogicException($where.'"'.$target.'" (mapped from "'.$legacy.'") is not a variable of the route');
					}
				}
				foreach (array_keys($rule->fixedParams) as $name) {
					if (!in_array((string) $name, $variables, true)) {
						throw new \LogicException($where.'fixed parameter "'.$name.'" is not a variable of the route');
					}
				}
				$rules[] = $rule;
			}
		}

		return new self($rules);
	}

	/**
	 * @return list<LegacyRedirect>
	 */
	public function forScript(string $script): array
	{
		return $this->byScript[$script] ?? [];
	}

	/**
	 * @return list<RuleArray>
	 */
	public function toArray(): array
	{
		$rows = [];
		foreach ($this->byScript as $rules) {
			foreach ($rules as $rule) {
				$rows[] = $rule->toArray();
			}
		}

		return $rows;
	}

	/**
	 * @param list<RuleArray> $a
	 */
	public static function fromArray(array $a): self
	{
		return new self(array_map(static fn (array $row): LegacyRedirect => LegacyRedirect::fromArray($row), $a));
	}
}
