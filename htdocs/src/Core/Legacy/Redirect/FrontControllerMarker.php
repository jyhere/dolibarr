<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy\Redirect;

/**
 * Empty marker file cache/mvc_front_ok: app.php has answered a matched route, so the front-controller rewrite
 * (nginx try_files or the Apache rules) is in place. The legacy redirects wait for it: without the rewrite the
 * routes would answer 404. Never throws.
 */
final class FrontControllerMarker
{
	public function __construct(private readonly string $dataRoot)
	{
	}

	public function file(): string
	{
		return $this->dataRoot.'/cache/mvc_front_ok';
	}

	public function exists(): bool
	{
		return is_file($this->file());
	}

	/**
	 * Create the marker when it is missing. Exclusive creation ('x'): concurrent requests cannot clash.
	 */
	public function mark(): void
	{
		$file = $this->file();
		if (file_exists($file)) {
			return;
		}
		$dir = dirname($file);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return;
		}
		$handle = @fopen($file, 'x');
		if ($handle !== false) {
			fclose($handle);
		}
	}
}
