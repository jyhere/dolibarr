<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\Documents;

use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Core\View\LatteRenderer;
use Dolibarr\Module\Documents\Controller\DocumentsController;
use Dolibarr\Tests\Unit\View\Fixture\FakeCurrentUser;
use Dolibarr\Tests\Unit\View\Fixture\FakeTranslator;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../User/Fixture/legacy_token_stub.php';

/**
 * documents.latte with stub fragments: the structure of user/document.php l.157-217 and of
 * core/tpl/document_actions_post_headers.tpl.php around the legacy fragments, output raw.
 */
final class DocumentsTemplateTest extends TestCase
{
	private const TEMPLATE_DIR = __DIR__.'/../../../../../../htdocs/src/Module/Documents/templates';

	public function testOneColumnLayout(): void
	{
		$html = $this->render(false);

		$this->assertInOrder($html, [
			'<i data-frag="tabs"></i>',
			'<i data-frag="banner"></i>',
			'<div class="fichecenter">'."\n".'<div class="underbanner clearboth"></div>',
			'<table class="border tableforfield centpercent">'."\n".'<i data-frag="rows"></i>'."\n".'</table>'."\n".'</div>',
			'<i data-frag="tabsEnd"></i>',
			'<i data-frag="confirm"></i>',
			'<i data-frag="documents"></i>'."\n".'<br>'."\n".'<i data-frag="links"></i>'."\n".'<br>',
		]);
		self::assertStringNotContainsString('fichehalfleft', $html);
		self::assertStringContainsString('<!--before-->', $html);
	}

	public function testTwoColumnsLayout(): void
	{
		$html = $this->render(true);

		$this->assertInOrder($html, [
			'<i data-frag="tabs"></i>',
			'<i data-frag="banner"></i>',
			'<div class="underbanner clearboth"></div>',
			'<i data-frag="rows"></i>',
			'<i data-frag="tabsEnd"></i>',
			'<i data-frag="confirm"></i>',
			'<div class="fichecenter">'."\n".'<div class="fichehalfleft">'."\n".'<i data-frag="documents"></i>'."\n".'</div>',
			'<div class="fichehalfright">'."\n".'<i data-frag="links"></i>'."\n".'</div>'."\n".'</div>'."\n".'<br>',
		]);
		self::assertStringNotContainsString('<i data-frag="documents"></i>'."\n".'<br>', $html);
	}

	/**
	 * @param list<string> $needles
	 */
	private function assertInOrder(string $html, array $needles): void
	{
		$offset = 0;
		foreach ($needles as $needle) {
			$position = strpos($html, $needle, $offset);
			self::assertNotFalse($position, 'Missing after offset '.$offset.': '.$needle."\n".$html);
			$offset = $position + strlen($needle);
		}
	}

	private function render(bool $twoColumns): string
	{
		$urls = new UrlGenerator((new RouteCompiler())->compile([DocumentsController::class]), '');
		$renderer = new LatteRenderer(
			sys_get_temp_dir().'/latte-test-'.getmypid(),
			['core' => dirname(self::TEMPLATE_DIR, 3).'/Core/templates', 'documents' => self::TEMPLATE_DIR],
			new DolibarrExtension(new FakeTranslator(), new FakeCurrentUser(), $urls),
			true
		);
		$layout = new class () {
			public function header(string $title, string $helpUrl, array $js, array $css, string $bodyCss): string
			{
				return '<!--header-->';
			}

			public function footer(): string
			{
				return '<!--footer-->';
			}
		};

		return self::withoutIndentation($renderer->render('documents:documents', [
			'layout' => $layout,
			'outputBeforeHeader' => '<!--before-->',
			'title' => 'Doe, John - Documents',
			'bodyCss' => 'mod-user page-card_document',
			'twoColumns' => $twoColumns,
			'chrome' => ['tabsHtml' => '<i data-frag="tabs"></i>', 'bannerHtml' => '<i data-frag="banner"></i>', 'tabsEndHtml' => '<i data-frag="tabsEnd"></i>', 'headerRowsHtml' => '<i data-frag="rows"></i>'],
			'fragments' => [
				'confirm' => '<i data-frag="confirm"></i>',
				'attachForms' => ['upload' => '<i data-frag="upload"></i>', 'link' => '<i data-frag="link"></i>'],
				'documents' => '<i data-frag="documents"></i>',
				'links' => '<i data-frag="links"></i>',
			],
		]));
	}

	/**
	 * The templates are indented for reading: the assertions compare the markup, not the leading tabs.
	 */
	private static function withoutIndentation(string $html): string
	{
		return (string) preg_replace('/^[\t ]+/m', '', $html);
	}
}
