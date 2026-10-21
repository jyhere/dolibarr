<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

use Dolibarr\Core\Module\ModuleInterface;

/**
 * Route definitions of a module list, from the file cache when usable (cache/routes.php).
 * Shared by the Kernel and by the legacy redirect table: both use the same stamp.
 */
final class RouteDefinitionsLoader
{
	/** @var list<RouteDefinition>|null */
	private ?array $definitions = null;

	/**
	 * @param string             $version     Application version (DOL_VERSION), part of the stamp
	 * @param list<ModuleInterface> $modules
	 * @param string|null        $modulesFile File the modules were loaded from (watched in dev), null when injected
	 */
	public function __construct(
		private readonly string $dataRoot,
		private readonly bool $prod,
		private readonly string $version,
		private readonly array $modules,
		private readonly ?string $modulesFile = null
	) {
	}

	/**
	 * Stamp shared by the route cache and the redirect cache: version and module classes.
	 */
	public function stamp(): string
	{
		return $this->version.'|'.sha1(implode(',', array_map(static fn (ModuleInterface $module): string => $module::class, $this->modules)));
	}

	/**
	 * @return list<RouteDefinition>
	 */
	public function load(): array
	{
		if ($this->definitions !== null) {
			return $this->definitions;
		}

		$controllers = [];
		foreach ($this->modules as $module) {
			foreach ($module->controllers() as $controller) {
				$controllers[] = $controller;
			}
		}

		$stamp = $this->stamp();
		$cache = new RouteCache($this->dataRoot.'/cache/routes.php');
		$definitions = null;
		if ($this->prod) {
			$definitions = $cache->load($stamp);
		} else {
			$files = $this->modulesFile !== null ? [$this->modulesFile] : [];
			foreach ($controllers as $controller) {
				$file = (new \ReflectionClass($controller))->getFileName();
				if ($file !== false) {
					$files[] = $file;
				}
			}
			if ($cache->isFresh($files)) {
				$definitions = $cache->load($stamp);
			}
		}

		if ($definitions === null) {
			$definitions = (new RouteCompiler())->compile($controllers);
			try {
				$cache->store($definitions, $stamp);
			} catch (\RuntimeException $e) {
				// The Logger service needs main.inc.php, not loaded yet: use the PHP error log.
				error_log('Dolibarr MVC: route cache not written, routes compiled for this request only: '.$e->getMessage());
			}
		}

		return $this->definitions = $definitions;
	}
}
