<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Core\View\LatteRenderer;
use Dolibarr\Module\User\Controller\UserListController;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\UserSearch;
use Dolibarr\Tests\Unit\Http\Fixture\FakeInputFilter;
use Dolibarr\Tests\Unit\Module\User\Fixture\ArrayConfig;
use Dolibarr\Tests\Unit\View\Fixture\FakeCurrentUser;
use Dolibarr\Tests\Unit\View\Fixture\FakeTranslator;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Fixture/legacy_token_stub.php';

/**
 * user_list.latte with stub rows: the structure of htdocs/user/list.php l.675-1484, the data escaped, the legacy
 * fragments raw.
 */
final class UserListTemplateTest extends TestCase
{
	private const TEMPLATE_DIR = __DIR__.'/../../../../../../htdocs/src/Module/User/templates';

	public function testRowsAreEscapedAndFragmentsRaw(): void
	{
		$html = $this->render(['search_login' => '"><script>x</script>'], '');

		// l.675-686
		self::assertStringContainsString('<form method="POST" id="searchFormList" action="/user">', $html);
		self::assertStringContainsString('<input type="hidden" name="token" value="stub-token">', $html);
		self::assertStringContainsString('<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">', $html);
		self::assertStringContainsString('<input type="hidden" name="sortfield" value="u.login">', $html);
		self::assertStringContainsString('<input type="hidden" name="contextpage" value="userlist">', $html);
		self::assertStringContainsString('<i data-frag="titleBar"></i>', $html);
		// The posted filter escaped
		self::assertStringContainsString('name="search_login" class="maxwidth50" value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $html);
		self::assertStringNotContainsString('<script>x</script>', $html);
		// The row: hostile data escaped, legacy cells raw
		self::assertStringContainsString('<tr data-rowid="7" class="oddeven row-with-select">', $html);
		self::assertStringContainsString('<td class="tdoverflowmax150" title="&lt;b&gt;Doe&lt;/b&gt;">&lt;b&gt;Doe&lt;/b&gt;</td>', $html);
		self::assertStringNotContainsString('<b>Doe</b>', $html);
		self::assertStringContainsString('<td class="nowraponall tdoverflowmax150"><i data-frag="login"></i></td>', $html);
		self::assertStringContainsString('<td class="center"><i data-frag="status"></i></td>', $html);
		self::assertStringContainsString('<input id="cb7" class="flat checkforselect" type="checkbox" name="toselect[]" value="7" checked="checked">', $html);
		self::assertStringContainsString('<i data-frag="th1"></i><i data-frag="th2"></i>'."\n".'<i data-frag="titleHook"></i>'."\n".'<i data-frag="th3"></i>', $html);
		self::assertStringContainsString('<i data-frag="rowHook"></i>', $html);
		self::assertStringContainsString('<i data-frag="footerHook"></i>', $html);
		self::assertStringNotContainsString('NoRecordFound', $html);
		self::assertStringNotContainsString('trkanban', $html);
	}

	public function testKanbanAndEmptyList(): void
	{
		$html = $this->render(['mode' => 'kanban'], 'kanban');
		self::assertStringContainsString('<tr class="trkanban"><td colspan="5"><div class="box-flex-container kanban">'."\n".'<i data-frag="kanban"></i>'."\n".'</div></td></tr>', $html);
		self::assertStringNotContainsString('data-rowid', $html);

		$html = $this->render([], '', false);
		self::assertStringContainsString('<tr><td colspan="4"><span class="opacitymedium">TR:NoRecordFound</span></td></tr>', $html);
	}

	/**
	 * @param array<string, mixed> $query
	 */
	private function render(array $query, string $mode, bool $withRow = true): string
	{
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserListController::class]), '');
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
		$search = UserSearch::fromRequest((new Request('GET', '/user', [], [], [], []))->withInputFilter(new FakeInputFilter($query)), new ArrayConfig(), false, 20);
		$frag = static fn (string $name): string => '<i data-frag="'.$name.'"></i>';
		$rows = $withRow ? [[
			'user' => new User(7, 1, '<i>bob</i>', lastname: '<b>Doe</b>', firstname: 'F'),
			'countryLabel' => '<c>',
			'importKey' => '',
			'selected' => 1,
			'kanbanHtml' => $frag('kanban'),
			'cells' => ['login' => $frag('login'), 'employee' => '', 'supervisor' => '', 'phone' => '', 'mobile' => '', 'email' => '', 'apiKey' => '', 'apiKeyTitle' => '', 'company' => '', 'entity' => null, 'salary' => '', 'thm' => '', 'lastLogin' => '', 'previousLogin' => '', 'extraFields' => '', 'dateCreation' => '', 'dateModification' => '', 'status' => $frag('status')],
			'hookHtml' => $frag('rowHook'),
		]] : [];

		return $renderer->render('user:user_list', [
			'layout' => $layout,
			'outputBeforeHeader' => '',
			'title' => 'Users',
			'helpUrl' => '',
			'bodyCss' => 'bodyforlist mod-user page-list',
			'formAction' => '/user',
			'userSearch' => $search,
			'search' => $search->filters,
			'show' => ['u.login' => true, 'u.lastname' => true, 'u.statut' => true],
			'privacy' => false,
			'checkboxLeft' => false,
			'titleBarHtml' => $frag('titleBar'),
			'categoryWaysHtml' => '',
			'searchAllHtml' => '',
			'moreForFilterHtml' => '',
			'widgets' => ['buttonsLeft' => '', 'buttonsRight' => $frag('buttons'), 'gender' => '', 'employee' => '', 'supervisor' => '', 'country' => '', 'status' => $frag('statusSelect')],
			'extraFieldsInputsHtml' => '',
			'optionHookHtml' => '',
			'titlesBefore' => [$frag('th1'), $frag('th2')],
			'titleHookHtml' => $frag('titleHook'),
			'titlesAfter' => [$frag('th3')],
			'kanbanColspan' => 5,
			'rows' => $rows,
			'totalsHtml' => '',
			'colspan' => 4,
			'footerHookHtml' => $frag('footerHook'),
			'items' => [],
			'total' => count($rows),
			'page' => 0,
			'limit' => 20,
		]);
	}
}
