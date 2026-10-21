<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy\Redirect;

/**
 * File cache of the compiled redirect table (cache/legacy_redirects.php), same stamp as the route cache.
 *
 * @phpstan-import-type RuleArray from LegacyRedirect
 */
final class RedirectCache
{
	public function __construct(private readonly string $file)
	{
	}

	/**
	 * @param string $expectedStamp Stamp the cache must have been stored with
	 * @return list<RuleArray>|null Null when there is no usable cache: missing, malformed or stored with another stamp
	 */
	public function load(string $expectedStamp): ?array
	{
		if (!is_file($this->file)) {
			return null;
		}
		$data = include $this->file;
		if (!is_array($data) || ($data['stamp'] ?? null) !== $expectedStamp || !isset($data['rules']) || !is_array($data['rules'])) {
			return null;
		}
		foreach ($data['rules'] as $row) {
			if (!is_array($row) || !isset($row['script'], $row['route'], $row['paramMap'], $row['fixedParams'], $row['required'], $row['forbidden'], $row['carry'], $row['queryFromAction'], $row['requirements'])
				|| !array_key_exists('actions', $row)) {
				return null;
			}
		}

		/** @var list<RuleArray> $rules */
		$rules = array_values($data['rules']);

		return $rules;
	}

	/**
	 * @param list<RuleArray> $table Compiled table, see LegacyRedirectTable::toArray()
	 * @param string          $stamp Version and module list the table was compiled for
	 * @throws \RuntimeException When the cache cannot be written
	 */
	public function store(array $table, string $stamp): void
	{
		$content = '<?php return '.var_export(['stamp' => $stamp, 'rules' => $table], true).';'."\n";

		$dir = dirname($this->file);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new \RuntimeException('Cannot create cache directory '.$dir);
		}
		if (!is_writable($dir)) {
			throw new \RuntimeException('Cache directory '.$dir.' is not writable');
		}
		$tmp = @tempnam($dir, 'redirects');
		if ($tmp === false || realpath(dirname($tmp)) !== realpath($dir)) {
			if ($tmp !== false) {
				@unlink($tmp);
			}
			throw new \RuntimeException('Cannot create temporary cache file in '.$dir);
		}
		if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $this->file)) {
			@unlink($tmp);
			throw new \RuntimeException('Cannot write redirect cache '.$this->file);
		}
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($this->file, true);
		}
	}
}
