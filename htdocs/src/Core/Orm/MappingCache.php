<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * File cache of entity mappings (DOL_DATA_ROOT/cache/orm_mappings.php), same mechanics as RouteCache: a PHP
 * file returning an array, written atomically. Each class has its own stamp (MappingRegistry::stamp()); a
 * missing, malformed or stale entry is a miss.
 */
final class MappingCache
{
	/** Version of the cached array shape, part of the stamps. */
	public const FORMAT = 1;

	/** @var array<string, array{stamp: string, mapping: array<mixed>}>|null */
	private ?array $entries = null;

	public function __construct(private readonly string $file)
	{
	}

	public function file(): string
	{
		return $this->file;
	}

	/**
	 * @param class-string $class
	 * @return EntityMapping|null Null when there is no usable entry: missing, malformed or stored with another stamp
	 */
	public function load(string $class, string $expectedStamp): ?EntityMapping
	{
		$entry = $this->entries()[$class] ?? null;
		if ($entry === null || $entry['stamp'] !== $expectedStamp) {
			return null;
		}
		try {
			$mapping = EntityMapping::fromArray($entry['mapping']);
		} catch (\UnexpectedValueException) {
			return null;
		}

		return $mapping->class === $class ? $mapping : null;
	}

	/**
	 * Adds or replaces the entry of the mapping's class; the other entries are kept.
	 *
	 * @throws \RuntimeException When the cache cannot be written
	 */
	public function store(EntityMapping $mapping, string $stamp): void
	{
		$entries = $this->entries();
		$entries[$mapping->class] = ['stamp' => $stamp, 'mapping' => $mapping->toArray()];
		$content = '<?php return '.var_export(['entries' => $entries], true).';'."\n";

		$dir = dirname($this->file);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new \RuntimeException('Cannot create cache directory '.$dir);
		}
		if (!is_writable($dir)) {
			throw new \RuntimeException('Cache directory '.$dir.' is not writable');
		}
		$tmp = @tempnam($dir, 'orm');
		if ($tmp === false || realpath(dirname($tmp)) !== realpath($dir)) {
			if ($tmp !== false) {
				@unlink($tmp);
			}
			throw new \RuntimeException('Cannot create temporary cache file in '.$dir);
		}
		if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $this->file)) {
			@unlink($tmp);
			throw new \RuntimeException('Cannot write ORM mapping cache '.$this->file);
		}
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($this->file, true);
		}
		$this->entries = $entries;
	}

	/**
	 * @return array<string, array{stamp: string, mapping: array<mixed>}>
	 */
	private function entries(): array
	{
		if ($this->entries !== null) {
			return $this->entries;
		}
		$this->entries = [];
		if (!is_file($this->file)) {
			return $this->entries;
		}
		$data = include $this->file;
		if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
			return $this->entries;
		}
		foreach ($data['entries'] as $class => $entry) {
			if (is_string($class) && is_array($entry) && is_string($entry['stamp'] ?? null) && is_array($entry['mapping'] ?? null)) {
				$this->entries[$class] = ['stamp' => $entry['stamp'], 'mapping' => $entry['mapping']];
			}
		}

		return $this->entries;
	}
}
