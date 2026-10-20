<?php

declare(strict_types=1);

namespace Dolibarr\Core\View;

use Latte\Loader;
use Latte\RuntimeException;
use Latte\TemplateNotFoundException;

/**
 * Latte loader resolving "<slug>:<name>" to "<templateDirs[slug]>/<name>.latte".
 */
final class TemplateLoader implements Loader
{
	/**
	 * @param array<string,string> $templateDirs
	 */
	public function __construct(private readonly array $templateDirs)
	{
	}

	public function getContent(string $name): string
	{
		$file = $this->path($name);
		if (!is_file($file)) {
			throw new TemplateNotFoundException("Missing template file '".$file."'.");
		}
		$content = file_get_contents($file);
		if ($content === false) {
			throw new RuntimeException("Unable to read file '".$file."'.");
		}

		return $content;
	}

	public function getReferredName(string $name, string $referringName): string
	{
		if (str_contains($name, ':')) {
			return $name;
		}
		$slug = explode(':', $referringName, 2)[0];

		return $slug.':'.$name;
	}

	public function getUniqueId(string $name): string
	{
		return $this->path($name);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function path(string $name): string
	{
		$parts = explode(':', $name, 2);
		if (count($parts) !== 2 || !isset($this->templateDirs[$parts[0]])) {
			throw new \InvalidArgumentException('Unknown template "'.$name.'", expected "<slug>:<name>" with a registered slug');
		}
		if (str_contains($parts[1], '..')) {
			throw new \InvalidArgumentException('Invalid template name "'.$name.'"');
		}

		return $this->templateDirs[$parts[0]].'/'.$parts[1].'.latte';
	}
}
