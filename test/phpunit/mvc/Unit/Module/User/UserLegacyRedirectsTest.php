<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Core\Legacy\Redirect\LegacyRedirector;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirectTable;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Module\Notes\NotesModule;
use Dolibarr\Module\User\Controller\LogoutController;
use Dolibarr\Module\User\Repository\UserSearch;
use Dolibarr\Module\User\UserModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The legacy redirect rules of the User module (user/card.php, list.php, note.php, perms.php, logout.php).
 */
final class UserLegacyRedirectsTest extends TestCase
{
	/**
	 * @return array<string,array{0:string,1:array<string,mixed>,2:?string}>
	 */
	public static function cases(): array
	{
		return [
			// card.php
			'card show' => ['user/card.php', ['id' => '1'], '/user/1'],
			'card show carry' => ['user/card.php', ['id' => '1', 'backtopage' => '/x', 'save_lastsearch_values' => '1', 'mainmenu' => 'home', 'leftmenu' => 'users', 'optioncss' => 'print', 'foo' => 'bar'], '/user/1?backtopage=%2Fx&save_lastsearch_values=1&mainmenu=home&leftmenu=users&optioncss=print'],
			'card edit' => ['user/card.php', ['id' => '1', 'action' => 'edit', 'backtopage' => '/x'], '/user/1/edit?backtopage=%2Fx'],
			'card disable' => ['user/card.php', ['id' => '4', 'action' => 'disable'], '/user/4?confirm=disable'],
			'card enable' => ['user/card.php', ['id' => '4', 'action' => 'enable'], '/user/4?confirm=enable'],
			'card delete' => ['user/card.php', ['id' => '4', 'action' => 'delete'], '/user/4?confirm=delete'],
			'card password' => ['user/card.php', ['id' => '4', 'action' => 'password'], '/user/4?confirm=password'],
			'card passwordsend' => ['user/card.php', ['id' => '4', 'action' => 'passwordsend'], '/user/4?confirm=passwordsend'],
			'card create' => ['user/card.php', ['action' => 'create', 'search_employee' => '1', 'socid' => '3', 'mainmenu' => 'home'], '/user/new?socid=3&search_employee=1&mainmenu=home'],
			'card create employee' => ['user/card.php', ['action' => 'create', 'employee' => '1', 'leftmenu' => 'hrm'], '/user/new?leftmenu=hrm&employee=1'],
			'card create global carry' => ['user/card.php', ['action' => 'create', 'dol_hide_leftmenu' => '1', 'lang' => 'de_DE'], '/user/new?dol_hide_leftmenu=1&lang=de_DE'],
			'card edit global carry' => ['user/card.php', ['id' => '1', 'action' => 'edit', 'optioncss' => 'print'], '/user/1/edit?optioncss=print'],
			'logout global carry' => ['user/logout.php', ['noredirect' => '1', 'dol_use_jmobile' => '1'], '/logout?noredirect=1&dol_use_jmobile=1'],
			'card create with id' => ['user/card.php', ['action' => 'create', 'id' => '2'], '/user/new'],
			'card clone' => ['user/card.php', ['id' => '1', 'action' => 'clone'], null],
			'card presend' => ['user/card.php', ['id' => '1', 'action' => 'presend'], null],
			'card builddoc' => ['user/card.php', ['id' => '1', 'action' => 'builddoc'], null],
			'card remove_file' => ['user/card.php', ['id' => '1', 'action' => 'remove_file'], null],
			'card adduserldap' => ['user/card.php', ['id' => '1', 'action' => 'adduserldap'], null],
			'card confirm_delete' => ['user/card.php', ['id' => '1', 'action' => 'confirm_delete'], null],
			'card confirm param' => ['user/card.php', ['id' => '1', 'confirm' => 'yes'], null],
			'card id zero' => ['user/card.php', ['id' => '0'], null],
			'card id leading zero' => ['user/card.php', ['id' => '01'], null],
			'card edit id zero' => ['user/card.php', ['id' => '0', 'action' => 'edit'], null],
			'card disable id zero' => ['user/card.php', ['id' => '0', 'action' => 'disable'], null],
			'card id not numeric' => ['user/card.php', ['id' => 'abc'], null],
			'card id mixed' => ['user/card.php', ['id' => '1abc', 'action' => 'edit'], null],
			'card without id nor action' => ['user/card.php', [], null],
			'card edit without id' => ['user/card.php', ['action' => 'edit'], null],
			// list.php
			'list plain' => ['user/list.php', [], '/user'],
			'list search sort page' => ['user/list.php', ['search_login' => 'adm', 'sortfield' => 'u.email', 'page' => '2'], '/user?search_login=adm&sortfield=u.email&page=2'],
			'list dates' => ['user/list.php', ['search_datelastloginday' => '3', 'search_datepreviousloginyear' => '2025', 'search_unknown' => 'x'], '/user?search_datelastloginday=3&search_datepreviousloginyear=2025'],
			'list misc' => ['user/list.php', ['catid' => '7', 'search_category_user_operator' => '1', 'limit' => '50', 'sortorder' => 'desc', 'mode' => 'kanban', 'contextpage' => 'userlist', 'optioncss' => 'print', 'restore_lastsearch_values' => '1', 'show_files' => '1'], '/user?search_category_user_operator=1&catid=7&sortorder=desc&limit=50&optioncss=print&mode=kanban&contextpage=userlist&restore_lastsearch_values=1&show_files=1'],
			'list massaction' => ['user/list.php', ['massaction' => 'preaffecttag'], null],
			'list confirmmassaction' => ['user/list.php', ['confirmmassaction' => 'x'], null],
			'list action' => ['user/list.php', ['action' => 'delete'], null],
			'list removefilter' => ['user/list.php', ['button_removefilter_x' => 'x'], null],
			'list removefilter dot' => ['user/list.php', ['button_removefilter.x' => 'x'], null],
			'list removefilter plain' => ['user/list.php', ['button_removefilter' => 'x'], null],
			'list formfilteraction' => ['user/list.php', ['formfilteraction' => 'list'], null],
			'list employeelist' => ['user/list.php', ['contextpage' => 'employeelist'], null],
			'list category array' => ['user/list.php', ['search_category_user_list' => ['3']], null],
			// note.php
			'note show' => ['user/note.php', ['id' => '2'], '/user/2/note'],
			'note edit public' => ['user/note.php', ['id' => '2', 'action' => 'editnote_public'], '/user/2/note?action=editnote_public'],
			'note edit private' => ['user/note.php', ['id' => '2', 'action' => 'editnote_private'], '/user/2/note?action=editnote_private'],
			'note edit keeps the token' => ['user/note.php', ['id' => '2', 'action' => 'editnote_public', 'token' => 'abc'], '/user/2/note?action=editnote_public&token=abc'],
			'list extrafield filter' => ['user/list.php', ['search_options_foo' => 'x'], null],
			'list empty extrafield filter' => ['user/list.php', ['search_options_foo' => ''], '/user'],
			'note set' => ['user/note.php', ['id' => '2', 'action' => 'setnote_public'], null],
			'note without id' => ['user/note.php', [], null],
			'note id zero' => ['user/note.php', ['id' => '0'], null],
			'note id not numeric' => ['user/note.php', ['id' => 'x'], null],
			// perms.php
			'perms show' => ['user/perms.php', ['id' => '2'], '/user/2/rights'],
			'perms carry' => ['user/perms.php', ['id' => '2', 'updatedmodulename' => 'user', 'page_y' => '120'], '/user/2/rights?updatedmodulename=user&page_y=120'],
			'perms addrights' => ['user/perms.php', ['id' => '2', 'action' => 'addrights', 'rights' => '5'], null],
			'perms delrights' => ['user/perms.php', ['id' => '2', 'action' => 'delrights'], null],
			'perms id zero' => ['user/perms.php', ['id' => '0'], null],
			'perms without id' => ['user/perms.php', [], null],
			// logout.php
			'logout' => ['user/logout.php', [], '/logout'],
			'logout carry' => ['user/logout.php', ['urlfrom' => '/a', 'noredirect' => '1', 'x' => 'y'], '/logout?urlfrom=%2Fa&noredirect=1'],
		];
	}

	/**
	 * @param array<string,mixed> $query
	 */
	#[DataProvider('cases')]
	public function testRule(string $script, array $query, ?string $expected): void
	{
		self::assertSame($expected, self::redirector()->resolve('GET', $script, $query, []), $script.' '.json_encode($query));
	}

	public function testLogoutRouteRunsEvenIfOnlyLoginAllowed(): void
	{
		$routes = array_column(array_map(static fn ($definition): array => $definition->toArray(), (new RouteCompiler())->compile([LogoutController::class])), null, 'name');

		// user/logout.php defines it: the offline message of main.inc.php links to it (redirected to /logout)
		self::assertSame(['EVEN_IF_ONLY_LOGIN_ALLOWED'], $routes['logout']['constants']);
	}

	public function testPostIsNeverRedirected(): void
	{
		self::assertNull(self::redirector()->resolve('POST', 'user/card.php', ['id' => '1'], []));
	}

	public function testEveryUserSearchFilterIsCarriedByTheList(): void
	{
		$filters = array_keys((new \ReflectionClassConstant(UserSearch::class, 'FILTERS'))->getValue());
		$query = [];
		foreach ($filters as $name) {
			$query[$name] = 'v';
		}
		$url = (string) self::redirector()->resolve('GET', 'user/list.php', $query, []);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $out);
		foreach ($filters as $name) {
			self::assertSame('v', $out[$name] ?? null, $name.' is not carried');
		}
	}

	public static function redirector(): LegacyRedirector
	{
		$modules = [new UserModule(), new NotesModule()];
		$controllers = [];
		foreach ($modules as $module) {
			$controllers = array_merge($controllers, $module->controllers());
		}
		$definitions = (new RouteCompiler())->compile($controllers);

		return new LegacyRedirector(LegacyRedirectTable::fromModules($modules, $definitions), new UrlGenerator($definitions, ''));
	}
}
