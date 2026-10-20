<?php

declare(strict_types=1);

namespace Dolibarr\Core\View;

use Latte\Engine;

/**
 * Renders "<slug>:<name>" templates with Latte (strict types, strict parsing).
 */
final class LatteRenderer
{
	private readonly Engine $engine;

	/** @var array<string,mixed> */
	private array $globals = [];

	/**
	 * @param array<string,string> $templateDirs Template directory per slug (must contain "core")
	 */
	public function __construct(string $cacheDir, array $templateDirs, DolibarrExtension $ext, bool $autoRefresh)
	{
		if (!is_dir($cacheDir)) {
			@mkdir($cacheDir, 0775, true);
		}
		$this->engine = new Engine();
		$this->engine->setTempDirectory($cacheDir);
		$this->engine->setStrictTypes(true);
		$this->engine->setStrictParsing(true);
		$this->engine->setAutoRefresh($autoRefresh);
		$this->engine->setLoader(new TemplateLoader($templateDirs));
		$this->engine->addExtension($ext);
	}

	public function addGlobal(string $name, mixed $value): void
	{
		$this->globals[$name] = $value;
	}

	/**
	 * @param array<string,mixed> $params
	 * @throws \InvalidArgumentException On unknown template slug
	 */
	public function render(string $template, array $params): string
	{
		return $this->engine->renderToString($template, $params + $this->globals);
	}
}
