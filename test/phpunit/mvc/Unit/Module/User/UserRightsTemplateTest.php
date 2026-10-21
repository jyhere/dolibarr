<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Core\View\LatteRenderer;
use Dolibarr\Module\User\Controller\UserRightsController;
use Dolibarr\Tests\Unit\View\Fixture\FakeCurrentUser;
use Dolibarr\Tests\Unit\View\Fixture\FakeTranslator;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Fixture/legacy_token_stub.php';

/**
 * user_rights.latte with stub rows: the structure of htdocs/user/perms.php l.497-956, the legacy pictos raw,
 * the texts escaped, the toggles as POST forms.
 */
final class UserRightsTemplateTest extends TestCase
{
	private const TEMPLATE_DIR = __DIR__.'/../../../../../../htdocs/src/Module/User/templates';

	public function testStructureOfPermsPhpWithRawPictosAndPostToggles(): void
	{
		$html = $this->render(true, false, false);

		self::assertStringContainsString('<i data-frag="tabs"></i>', $html);
		self::assertStringContainsString('<table class="border centpercent tableforfield">'."\n".'<i data-frag="rows"></i>', $html);
		self::assertStringContainsString('<i data-frag="infoAdmin"></i>', $html);
		self::assertStringContainsString('<i data-frag="headerHook"></i>', $html);
		self::assertStringContainsString('<i data-frag="footerHook"></i>', $html);
		self::assertStringContainsString('<i data-frag="tabsEnd"></i>', $html);
		// l.507-524: All / None of every module, expand / collapse
		self::assertStringContainsString('<input type="hidden" name="action" value="addrights"><input type="hidden" name="module" value="allmodules"><input type="hidden" name="confirm" value="yes"><input type="hidden" name="page_y" value=""><input type="submit"', $html);
		self::assertStringContainsString('<a class="showallperms" title="TR:ShowAllPerms" alt="TR:ShowAllPerms" href="#"><i data-frag="expandAll"></i>', $html);
		// l.658-663: the break line
		self::assertStringContainsString('<tr class="oddeven trforbreakperms trforbreaknobg" data-hide-perms="user" data-j="1">', $html);
		self::assertStringContainsString('<input type="hidden" name="forbreakperms_user" id="idforbreakperms_user" css="cssforfieldishiden" data-j="1" value="0"><i data-frag="picto"></i> Users &amp; Groups<a name="Users &amp; Groups"></a>', $html);
		// l.671: All / None of the module, with updatedmodulename
		self::assertStringContainsString('<input type="hidden" name="module" value="user"><input type="hidden" name="confirm" value="yes"><input type="hidden" name="updatedmodulename" value="user">', $html);
		// l.745-753: owned, a POST removing it; l.788-797: not owned, a POST adding it
		self::assertStringContainsString('<!-- user has perm --><td class="center nowrap"><form method="POST" action="/user/3/rights" class="inline-block"><input type="hidden" name="token" value="stub-token"><input type="hidden" name="action" value="delrights"><input type="hidden" name="rights" value="251">', $html);
		// The scroll position, filled by the .reposition handler of lib_foot.js.php and kept by the redirect
		self::assertStringContainsString('<input type="hidden" name="updatedmodulename" value="user"><input type="hidden" name="page_y" value=""><button type="submit" id="251"', $html);
		self::assertSame(substr_count($html, '<form method="POST"'), substr_count($html, 'name="page_y"'));
		self::assertStringContainsString('<button type="submit" id="251" class="reposition addexpandedmodulesinparamlist nobordertransp nopadding cursorpointer"><i data-frag="remove"></i></button></form>', $html);
		self::assertStringContainsString('<!-- do not own permission --><td class="center nowrap"><form method="POST" action="/user/3/rights" class="inline-block"><input type="hidden" name="token" value="stub-token"><input type="hidden" name="action" value="addrights"><input type="hidden" name="rights" value="252">', $html);
		// The label escaped, the badge and warnings raw, the descriptions, the admin id column
		self::assertStringContainsString('<i data-frag="badge"></i>TR:&lt;script&gt;alert(1)&lt;/script&gt;<br><span class="opacitymedium">TR:Permission251b</span><i data-frag="warn"></i></td>', $html);
		self::assertStringNotContainsString('<script>alert(1)', $html);
		self::assertStringContainsString('<td class="right"><i data-frag="id"></i></td>', $html);
		self::assertStringContainsString('<!-- user->user->lire -->', $html);
		// l.880-950: the collapse script and its cookie, verbatim
		self::assertStringContainsString('document.cookie = "DOLUSER_PERMS_HIDE_GRP=" + hideuserperm + "; expires=" + date.toGMTString() + "; path=/ ";', $html);
		self::assertStringContainsString('$(".tdforbreakperms:not(.alink)").on("click", function(){', $html);
		self::assertStringNotContainsString('href="/user/3/rights?', $html);
	}

	public function testReadOnlyCollapsedAndInherited(): void
	{
		$html = $this->render(false, true, true);

		self::assertStringNotContainsString('<form', $html);
		self::assertStringNotContainsString('allmodules', $html);
		// Collapsed (l.661, l.670, l.702-706, l.725)
		self::assertStringContainsString('data-j="1" value="1">', $html);
		self::assertStringContainsString('<tr class="oddeven trtohide_user" style="display:none">', $html);
		self::assertStringContainsString('<div class="switchfolderperms inline-block marginrightonly folderperms_user"><i data-frag="folder"></i></div><div class="switchfolderperms inline-block marginrightonly folderopenperms_user" style="display:none;">', $html);
		// l.754-757: owned, read only
		self::assertStringContainsString('<!-- user has perm --><td class="center nowrap"><i data-frag="active"></i></td><td></td>', $html);
		// l.761-770: inherited
		self::assertStringContainsString('<!-- permsgroupbyentitypluszero --><td class="center nowrap"><i data-frag="active"></i></td><td class="center nowrap"><i data-frag="inherited"></i></td>', $html);
		// Not admin: the description on 2 columns, no id column (l.808-871)
		self::assertStringContainsString('<td colspan="2">', $html);
		self::assertStringNotContainsString('<i data-frag="id"></i>', $html);
	}

	public function testAdminTargetOfAnAdminAllowedModule(): void
	{
		$html = $this->render(true, false, false, true);

		// l.732-737 and l.682-691
		self::assertStringContainsString('<!-- perm is a perm allowed to any admin --><td class="center nowrap"><i data-frag="admin"></i></td><td></td>', $html);
		self::assertStringContainsString('<td class="tdforbreakperms center wraponsmartphone" data-hide-perms="user"></td>', $html);
		self::assertStringNotContainsString('name="updatedmodulename"', $html);
	}

	private function render(bool $canEdit, bool $hidden, bool $inherited, bool $targetIsAdmin = false): string
	{
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserRightsController::class]), '');
		$renderer = new LatteRenderer(
			sys_get_temp_dir().'/latte-test-'.getmypid(),
			['core' => dirname(self::TEMPLATE_DIR, 3).'/Core/templates', 'user' => self::TEMPLATE_DIR],
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
		$frags = [];
		foreach (['infoAdminHtml' => 'infoAdmin', 'externalWarningHtml' => 'external', 'expandAllPicto' => 'expandAll', 'collapseAllPicto' => 'collapseAll', 'folderPicto' => 'folder', 'folderOpenPicto' => 'folderOpen', 'adminPicto' => 'admin', 'activePicto' => 'active', 'removePicto' => 'remove', 'addPicto' => 'add', 'disabledPicto' => 'disabled', 'inheritedHtml' => 'inherited'] as $key => $name) {
			$frags[$key] = '<i data-frag="'.$name.'"></i>';
		}
		$right = static fn (int $id, string $sub, bool $granted, bool $inh, string $label): array => [
			'id' => $id, 'perms' => 'user', 'subperms' => $sub, 'granted' => $granted, 'inherited' => $inh,
			'labelKey' => $label, 'descriptionKeys' => $id === 251 ? ['Permission251b'] : [], 'advancedOnly' => false,
			'badgeHtml' => $id === 251 ? '<i data-frag="badge"></i>' : '', 'warningsHtml' => $id === 251 ? '<i data-frag="warn"></i>' : '',
			'idHtml' => $canEdit ? '<i data-frag="id"></i>' : '',
		];

		return self::withoutIndentation($renderer->render('user:user_rights', [
			'layout' => $layout,
			'outputBeforeHeader' => '',
			'title' => 'T',
			'bodyCss' => 'mod-user page-card_perms',
			'formAction' => '/user/3/rights',
			'chrome' => ['tabsHtml' => '<i data-frag="tabs"></i>', 'bannerHtml' => '<i data-frag="banner"></i>', 'tabsEndHtml' => '<i data-frag="tabsEnd"></i>', 'headerRowsHtml' => '<i data-frag="rows"></i>'],
			'fragments' => $frags,
			'canEdit' => $canEdit,
			'meIsAdmin' => $canEdit,
			'targetIsAdmin' => $targetIsAdmin,
			'hasGroupRights' => $inherited,
			'modules' => [[
				'module' => 'user',
				'j' => 1,
				'name' => 'Users & Groups',
				'pictoHtml' => '<i data-frag="picto"></i>',
				'adminAllowed' => true,
				'hidden' => $hidden,
				'rights' => [$right(251, 'lire', true, false, '<script>alert(1)</script>'), $right(252, 'creer', false, $inherited, 'Permission252')],
			]],
			'headerHookHtml' => '<i data-frag="headerHook"></i>',
			'footerHookHtml' => '<i data-frag="footerHook"></i>',
			'rights' => [],
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
