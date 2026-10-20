<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

/**
 * File cache of compiled route definitions.
 *
 * @phpstan-import-type RouteArray from RouteDefinition
 */
final class RouteCache
{
	public function __construct(private readonly string $file)
	{
	}

	/**
	 * @return list<RouteDefinition>|null Null when there is no usable cache
	 */
	public function load(): ?array
	{
		if (!is_file($this->file)) {
			return null;
		}
		$data = include $this->file;
		if (!is_array($data)) {
			return null;
		}

		$definitions = [];
		foreach ($data as $row) {
			/** @var RouteArray $row */
			$definitions[] = RouteDefinition::fromArray($row);
		}

		return $definitions;
	}

	/**
	 * @param list<RouteDefinition> $definitions
	 * @throws \RuntimeException When the cache cannot be written
	 */
	public function store(array $definitions): void
	{
		$rows = array_map(static fn(RouteDefinition $d): array => $d->toArray(), $definitions);
		$content = '<?php return '.var_export($rows, true).';'."\n";

		$dir = dirname($this->file);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new \RuntimeException('Cannot create cache directory '.$dir);
		}
		if (!is_writable($dir)) {
			throw new \RuntimeException('Cache directory '.$dir.' is not writable');
		}
		$tmp = @tempnam($dir, 'routes');
		if ($tmp === false || realpath(dirname($tmp)) !== realpath($dir)) {
			if ($tmp !== false) {
				@unlink($tmp);
			}
			throw new \RuntimeException('Cannot create temporary cache file in '.$dir);
		}
		if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $this->file)) {
			@unlink($tmp);
			throw new \RuntimeException('Cannot write route cache '.$this->file);
		}
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($this->file, true);
		}
	}

	/**
	 * @param list<string> $controllerFiles
	 */
	public function isFresh(array $controllerFiles): bool
	{
		$cacheTime = @filemtime($this->file);
		if ($cacheTime === false) {
			return false;
		}
		foreach ($controllerFiles as $file) {
			$time = @filemtime($file);
			if ($time === false || $time > $cacheTime) {
				return false;
			}
		}

		return true;
	}
}
