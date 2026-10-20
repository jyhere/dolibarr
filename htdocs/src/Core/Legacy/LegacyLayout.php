<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

/**
 * Legacy page chrome (llxHeader/llxFooter), available only when main.inc.php was included.
 */
final class LegacyLayout
{
	/**
	 * @param list<string> $css
	 * @param list<string> $js
	 * @param string       $bodyCss CSS classes of <body>, e.g. 'mod-user page-card'
	 */
	public function header(string $title, string $helpUrl = '', array $css = [], array $js = [], string $bodyCss = ''): string
	{
		$this->assertAvailable();
		ob_start();
		llxHeader('', $title, $helpUrl, '', 0, 0, $js, $css, '', $bodyCss);

		return (string) ob_get_clean();
	}

	public function footer(): string
	{
		$this->assertAvailable();
		ob_start();
		llxFooter();

		return (string) ob_get_clean();
	}

	private function assertAvailable(): void
	{
		if (!function_exists('llxHeader') || !function_exists('llxFooter')) {
			throw new \LogicException('llxHeader() is not available: main.inc.php was not included');
		}
	}
}
