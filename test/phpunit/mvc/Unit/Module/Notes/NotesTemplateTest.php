<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\Notes;

use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Core\View\LatteRenderer;
use Dolibarr\Module\Notes\Controller\NotesController;
use Dolibarr\Tests\Unit\View\Fixture\FakeCurrentUser;
use Dolibarr\Tests\Unit\View\Fixture\FakeTranslator;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../User/Fixture/legacy_token_stub.php';

/**
 * notes.latte with stub fragments: the structure of core/tpl/notes.tpl.php around the legacy fragments, output raw.
 */
final class NotesTemplateTest extends TestCase
{
	private const TEMPLATE_DIR = __DIR__.'/../../../../../../htdocs/src/Module/Notes/templates';

	public function testStructureOfTheLegacyTemplateWithRawFragments(): void
	{
		$html = $this->render('', true);

		self::assertStringContainsString('<!-- BEGIN PHP TEMPLATE NOTES -->', $html);
		self::assertStringContainsString('<!-- END PHP TEMPLATE NOTES-->', $html);
		self::assertStringContainsString('<div class="tagtable border table-border tableforfield centpercent">', $html);
		self::assertSame(2, substr_count($html, '<div class="tagtr table-border-row">'));
		self::assertStringContainsString('<div class="tagtd tagtdnote tdtop sensiblehtmlcontent table-key-border-col" style="width: 25%">'."\n".'<i data-frag="publicKey"></i>', $html);
		self::assertStringContainsString('<div class="tagtd wordbreak table-val-border-col sensiblehtmlcontent">'."\n".'<i data-frag="publicVal"></i>', $html);
		self::assertStringContainsString('<i data-frag="privateKey"></i>', $html);
		self::assertStringContainsString('<i data-frag="privateVal"></i>', $html);
		// note.php l.137-138: the outer form posting to the page, with its token
		self::assertStringContainsString('<form method="POST" action="/user/3/note">'."\n".'<input type="hidden" name="token" value="stub-token">', $html);
		self::assertStringContainsString('<i data-frag="tabs"></i>', $html);
		self::assertStringContainsString('<i data-frag="banner"></i>', $html);
		self::assertStringContainsString('<div class="underbanner clearboth"></div>', $html);
		self::assertStringContainsString('<table class="border centpercent tableforfield">'."\n".'<i data-frag="rows"></i>', $html);
		self::assertStringContainsString('<i data-frag="tabsEnd"></i>', $html);
		self::assertStringContainsString('<!--before-->', $html);
	}

	public function testEditModeDropsSensibleClassOfThatNoteOnly(): void
	{
		$html = $this->render('editnote_public', true);

		self::assertStringContainsString('<div class="tagtd tagtdnote tdtop table-key-border-col" style="width: 25%">'."\n".'<i data-frag="publicKey"></i>', $html);
		self::assertStringContainsString('<div class="tagtd wordbreak table-val-border-col">'."\n".'<i data-frag="publicVal"></i>', $html);
		self::assertStringContainsString('<div class="tagtd tagtdnote tdtop sensiblehtmlcontent table-key-border-col" style="width: 25%">'."\n".'<i data-frag="privateKey"></i>', $html);
	}

	public function testPrivateRowAndHeaderTableOmitted(): void
	{
		$html = $this->render('', false, '');

		self::assertSame(1, substr_count($html, '<div class="tagtr table-border-row">'));
		self::assertStringNotContainsString('privateKey', $html);
		self::assertStringNotContainsString('<div class="fichecenter">', $html);
	}

	private function render(string $action, bool $private, string $rows = '<i data-frag="rows"></i>'): string
	{
		$urls = new UrlGenerator((new RouteCompiler())->compile([NotesController::class]), '');
		$renderer = new LatteRenderer(
			sys_get_temp_dir().'/latte-test-'.getmypid(),
			['core' => dirname(self::TEMPLATE_DIR, 3).'/Core/templates', 'notes' => self::TEMPLATE_DIR],
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

		return $renderer->render('notes:notes', [
			'layout' => $layout,
			'outputBeforeHeader' => '<!--before-->',
			'title' => '',
			'bodyCss' => 'mod-user page-card_note',
			'formAction' => '/user/3/note',
			'action' => $action,
			'permissionNote' => true,
			'chrome' => ['tabsHtml' => '<i data-frag="tabs"></i>', 'bannerHtml' => '<i data-frag="banner"></i>', 'tabsEndHtml' => '<i data-frag="tabsEnd"></i>', 'headerRowsHtml' => $rows],
			'fragments' => [
				'publicKey' => '<i data-frag="publicKey"></i>',
				'publicVal' => '<i data-frag="publicVal"></i>',
				'privateKey' => $private ? '<i data-frag="privateKey"></i>' : null,
				'privateVal' => $private ? '<i data-frag="privateVal"></i>' : null,
			],
		]);
	}
}
