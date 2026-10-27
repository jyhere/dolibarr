<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * Entity mappings for the request: memory, then the file cache when its entry is fresh, then MappingReader.
 * The stamp of a class is the Dolibarr version, the cache format and the hash of the class file, so an edited
 * entity or a deployment invalidates its entry. Only the entity's own file is hashed: entities are final classes
 * mapped by their promoted constructor, so no parent class or trait contributes to the mapping (an enum or a
 * relation target is referenced by name only).
 */
final class MappingRegistry
{
	/** @var array<class-string, EntityMapping> */
	private array $mappings = [];

	/**
	 * @param string $version Application version (DOL_VERSION)
	 */
	public function __construct(
		private readonly MappingReader $reader = new MappingReader(),
		private readonly ?MappingCache $cache = null,
		private readonly string $version = ''
	) {
	}

	/**
	 * @param class-string $class
	 * @throws \LogicException When the class is not a valid entity mapping
	 */
	public function get(string $class): EntityMapping
	{
		if (isset($this->mappings[$class])) {
			return $this->mappings[$class];
		}
		if ($this->cache === null) {
			return $this->mappings[$class] = $this->reader->read($class);
		}

		$stamp = $this->stamp($class);
		$mapping = $this->cache->load($class, $stamp);
		if ($mapping === null) {
			$mapping = $this->reader->read($class);
			try {
				$this->cache->store($mapping, $stamp);
			} catch (\RuntimeException $e) {
				error_log('Dolibarr MVC: ORM mapping cache not written, mapping read for this request only: '.$e->getMessage());
			}
		}

		return $this->mappings[$class] = $mapping;
	}

	/**
	 * @param class-string $class
	 */
	public function stamp(string $class): string
	{
		$file = (new \ReflectionClass($class))->getFileName();
		$hash = $file !== false ? sha1_file($file) : false;

		return $this->version.'|'.MappingCache::FORMAT.'|'.($hash !== false ? $hash : 'nofile');
	}
}
