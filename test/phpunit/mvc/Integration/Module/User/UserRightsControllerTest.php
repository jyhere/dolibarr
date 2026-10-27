<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyCurrentUser;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyInputFilter;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTranslator;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Module\Notes\Controller\NotesController;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Controller\UserListController;
use Dolibarr\Module\User\Controller\UserRightsController;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\RightsRepository;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\View\RightsChrome;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use Dolibarr\Tests\Integration\Fixture\ScriptedHooks;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

/**
 * The rights tab (htdocs/user/perms.php). Every test runs in a transaction rolled back in tearDown; the
 * disposable users are created inside it.
 */
final class UserRightsControllerTest extends TestCase
{
	use OverridesLegacyConf;

	private LegacyContext $ctx;

	private Connection $connection;

	private ?\stdClass $browser = null;

	/** @var array<mixed> */
	private array $session = [];

	/** @var array<mixed> */
	private array $get = [];

	/** @var array<mixed> */
	private array $post = [];

	/** @var array<mixed> */
	private array $cookie = [];

	protected function setUp(): void
	{
		$this->get = $_GET;
		$this->post = $_POST;
		$this->cookie = $_COOKIE;
		$this->session = $_SESSION ?? [];
		$_SESSION = [];
		$this->ctx = LegacyContext::fromGlobals();
		$this->connection = new Connection($this->ctx->db);
		$conf = $this->ctx->conf;
		$this->browser = $conf->browser;
		$conf->browser = clone $conf->browser;
		if (!isset($conf->browser->layout)) {
			$conf->browser->layout = 'classic';
		}
		$this->disableUserAgendaEvents();
		$this->ctx->langs->loadLangs(['main', 'users', 'admin', 'errors']);
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
		$this->restoreConf();
		$this->ctx->conf->browser = $this->browser;
		$_SESSION = $this->session;
		$_GET = $this->get;
		$_POST = $this->post;
		$_COOKIE = $this->cookie;
	}

	public function testAddAndDelRightForDisposableUser(): void
	{
		$target = $this->disposable('Target');
		$this->connection->legacyExecute('DELETE FROM '.MAIN_DB_PREFIX.'user_rights WHERE fk_user = '.$target->id);
		$read = $this->rightId('user', 'user', 'lire');

		// perms.php l.114-139: all the rights of a module, then the tab without action, token nor confirm
		$response = $this->controller()->update($this->post($target->id, ['action' => 'addrights', 'module' => 'user', 'confirm' => 'yes', 'updatedmodulename' => 'user', 'page_y' => '0']), $target->id);
		$this->assertRedirectsToRights($response, $target->id, '?updatedmodulename=user');
		$userRights = $this->rows('user_rights ur JOIN '.MAIN_DB_PREFIX."rights_def r ON r.id = ur.fk_id AND r.entity = ur.entity WHERE r.module = 'user' AND ur.fk_user = ".$target->id);
		self::assertSame($this->rows("rights_def WHERE module = 'user' AND entity = ".((int) $this->ctx->conf->entity)), $userRights);
		self::assertSame([], $_SESSION['dol_events'] ?? []);

		// The page shows them granted, the JSON too
		$view = $this->controller()->show($this->request('GET', [], [], $target->id), $target->id);
		$exposed = $view->exposed['rights'];
		self::assertIsArray($exposed);
		$user = array_values(array_filter($exposed, static fn (mixed $module): bool => is_array($module) && $module['module'] === 'user'))[0];
		self::assertIsArray($user['rights']);
		self::assertNotSame([], $user['rights']);
		foreach ($user['rights'] as $right) {
			self::assertIsArray($right);
			self::assertSame(['id', 'perms', 'subperms', 'label', 'granted', 'inherited'], array_keys($right));
			self::assertTrue($right['granted']);
		}

		// l.142-167: one right; the scroll position kept by the redirect (l.162-166 keep the query string)
		$response = $this->controller()->update($this->post($target->id, ['action' => 'delrights', 'rights' => $read, 'confirm' => 'yes', 'page_y' => '420']), $target->id);
		$this->assertRedirectsToRights($response, $target->id, '?page_y=420');
		self::assertSame(0, $this->rows('user_rights WHERE fk_user = '.$target->id.' AND fk_id = '.$read));
		// delrights() also removes the rights needing the read one (user->user->*)
		self::assertLessThan($userRights, $this->rows('user_rights WHERE fk_user = '.$target->id));
		self::assertGreaterThan(0, $this->rows('user_rights WHERE fk_user = '.$target->id));

		// A module
		$this->controller()->update($this->post($target->id, ['action' => 'delrights', 'module' => 'user', 'confirm' => 'yes']), $target->id);
		self::assertSame(0, $this->rows('user_rights WHERE fk_user = '.$target->id));
	}

	public function testConfirmIsRequired(): void
	{
		$target = $this->disposable('Target');
		$this->connection->legacyExecute('DELETE FROM '.MAIN_DB_PREFIX.'user_rights WHERE fk_user = '.$target->id);

		$response = $this->controller()->update($this->post($target->id, ['action' => 'addrights', 'module' => 'user']), $target->id);

		$this->assertRedirectsToRights($response, $target->id, '');
		self::assertSame(0, $this->rows('user_rights WHERE fk_user = '.$target->id));
	}

	public function testNonAdminWithoutWriteCannotEditRights(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');
		$this->connection->legacyExecute('DELETE FROM '.MAIN_DB_PREFIX.'user_rights WHERE fk_user = '.$target->id);
		$me = new RightsCurrentUser($actor->id, false, ['user.user.read']);

		// He reads the rights (l.67), without the toggles (l.69)
		$view = $this->controller($me)->show($this->request('GET', [], [], $target->id), $target->id);
		self::assertFalse($view->data['canEdit']);

		try {
			$this->controller($me)->update($this->post($target->id, ['action' => 'addrights', 'module' => 'user', 'confirm' => 'yes']), $target->id);
			self::fail('AccessDeniedException expected');
		} catch (AccessDeniedException) {
		}
		self::assertSame(0, $this->rows('user_rights WHERE fk_user = '.$target->id));
	}

	public function testUnreadableUserIsForbiddenAndUnknownIsNotFound(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');

		try {
			$this->controller(new RightsCurrentUser($actor->id))->show($this->request('GET', [], [], $target->id), $target->id);
			self::fail('AccessDeniedException expected');
		} catch (AccessDeniedException) {
		}
		$this->expectException(NotFoundException::class);
		$this->controller()->show($this->request('GET', [], [], 999999999), 999999999);
	}

	public function testInvalidTokenOnRightsWritesNothing(): void
	{
		$target = $this->disposable('Target');
		$this->connection->legacyExecute('DELETE FROM '.MAIN_DB_PREFIX.'user_rights WHERE fk_user = '.$target->id);
		$hooks = new ScriptedHooks([]);

		// main.inc.php on an invalid token: $_POST emptied, action and confirm removed from $_GET, errorcode set
		$response = $this->controller(null, $hooks)->update($this->request('POST', ['errorcode' => 'InvalidToken', 'module' => 'user'], [], $target->id), $target->id);

		$this->assertRedirectsToRights($response, $target->id, '');
		self::assertSame('', $hooks->actions['doActions']);
		self::assertSame(0, $this->rows('user_rights WHERE fk_user = '.$target->id));
	}

	public function testDoActionsCanVetoTheAction(): void
	{
		$target = $this->disposable('Target');
		$this->connection->legacyExecute('DELETE FROM '.MAIN_DB_PREFIX.'user_rights WHERE fk_user = '.$target->id);
		$hooks = new ScriptedHooks(['doActions' => ['code' => -1, 'errors' => ['Hook says no']]]);

		$this->controller(null, $hooks)->update($this->post($target->id, ['action' => 'addrights', 'module' => 'user', 'confirm' => 'yes']), $target->id);

		self::assertSame('addrights', $hooks->actions['doActions']);
		// perms.php l.107
		self::assertSame(0, $hooks->parameters['doActions']['socid'] ?? null);
		self::assertSame(0, $this->rows('user_rights WHERE fk_user = '.$target->id));
		self::assertSame(['Hook says no'], $_SESSION['dol_events']['errors'] ?? []);
	}

	public function testShowRunsTheHooksOfThePage(): void
	{
		$target = $this->disposable('Target');
		$hooks = new ScriptedHooks(['insertExtraHeader' => ['echo' => '<i>header</i>'], 'insertExtraFooter' => ['echo' => '<i>footer</i>']]);

		$view = $this->controller(null, $hooks)->show($this->request('GET', [], [], $target->id), $target->id);

		self::assertSame(['doActions', 'insertExtraHeader', 'insertExtraFooter'], $hooks->calls);
		self::assertArrayHasKey('permsgroupbyentity', $hooks->parameters['insertExtraHeader']);
		self::assertSame('<i>header</i>', $view->data['headerHookHtml']);
		self::assertSame('<i>footer</i>', $view->data['footerHookHtml']);
		self::assertSame('user:user_rights', $view->template);
		self::assertStringContainsString('id="rights"', $view->data['chrome']['tabsHtml']);
		self::assertStringContainsString('href="/user/'.$target->id.'/rights"', $view->data['chrome']['tabsHtml']);
		self::assertStringContainsString('id="anchorforperms"', $view->data['chrome']['headerRowsHtml']);
	}

	public function testCollapsedModulesFromTheCookieAndTheParameter(): void
	{
		$target = $this->disposable('Target');
		$_COOKIE['DOLUSER_PERMS_HIDE_GRP'] = ',1';

		$view = $this->controller()->show($this->request('GET', [], [], $target->id), $target->id);
		$modules = $view->data['modules'];
		self::assertTrue($modules[0]['hidden']);
		self::assertSame(1, $modules[0]['j']);

		$view = $this->controller()->show($this->request('GET', ['forbreakperms_'.$modules[0]['module'] => '0'], [], $target->id), $target->id);
		self::assertFalse($view->data['modules'][0]['hidden']);
	}

	public function testSelfWithAdvancedPermsWithoutReadpermsIsForbidden(): void
	{
		$actor = $this->disposable('Actor');
		// perms.php l.84: with MAIN_USE_ADVANCED_PERMS, a non-admin needs user->self_advance->readperms on himself
		$me = new RightsCurrentUser($actor->id, false, ['user.user.read', 'user.user_advance.readperms']);

		try {
			$this->controller($me, null, true)->show($this->request('GET', [], [], $actor->id), $actor->id);
			self::fail('AccessDeniedException expected');
		} catch (AccessDeniedException) {
		}

		$me = new RightsCurrentUser($actor->id, false, ['user.self_advance.readperms']);
		$view = $this->controller($me, null, true)->show($this->request('GET', [], [], $actor->id), $actor->id);
		self::assertFalse($view->data['canEdit']);
	}

	public function testBannerPreviousNextLinksTargetTheCurrentTabRoute(): void
	{
		$firstUser = $this->disposable('NavA');
		$secondUser = $this->disposable('NavB');
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class, UserListController::class, UserRightsController::class, NotesController::class]), '');
		$chrome = new UserCardChrome($this->ctx, $urls);
		$legacy = (new LegacyObjectLoader($this->connection))->user($firstUser->id);
		self::assertNotNull($legacy);
		$oldSelf = $_SERVER['PHP_SELF'] ?? null;

		try {
			foreach (['rights' => '/user/'.$secondUser->id.'/rights', 'note' => '/user/'.$secondUser->id.'/note'] as $tab => $expected) {
				$_SERVER['PHP_SELF'] = '/user/'.$firstUser->id.($tab === 'rights' ? '/rights' : '/note');
				$banner = $chrome->chrome($legacy, $firstUser, $tab)['bannerHtml'];
				self::assertStringContainsString('href="'.$expected.'"', $banner, $tab);
				self::assertStringNotContainsString('href="'.$_SERVER['PHP_SELF'].'?id=', $banner, $tab);
			}

			$_SERVER['PHP_SELF'] = '/user/'.$firstUser->id;
			$card = $chrome->build($legacy, $firstUser, new UserPermissionChecker($this->legacyMe(), false, false, false, [], false, false, (int) $this->ctx->conf->entity), false, 2)->bannerHtml;
			self::assertStringContainsString('href="/user/'.$secondUser->id.'"', $card);
			self::assertStringNotContainsString('href="'.$_SERVER['PHP_SELF'].'?id=', $card);
		} finally {
			if ($oldSelf === null) {
				unset($_SERVER['PHP_SELF']);
			} else {
				$_SERVER['PHP_SELF'] = $oldSelf;
			}
		}
	}

	private function assertRedirectsToRights(mixed $response, int $id, string $query): void
	{
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/'.$id.'/rights'.$query, $response->headers()['Location']);
	}

	private function rows(string $from): int
	{
		$row = $this->connection->legacyFetchOneObject('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.$from);

		return (int) ($row?->nb ?? 0);
	}

	private function rightId(string $module, string $perms, string $subperms): int
	{
		$row = $this->connection->legacyFetchOneObject('SELECT id FROM '.MAIN_DB_PREFIX."rights_def WHERE module = '".$module."' AND perms = '".$perms."' AND subperms = '".$subperms."' AND entity = ".((int) $this->ctx->conf->entity));
		self::assertNotNull($row);

		return (int) $row->id;
	}

	private function disposable(string $lastname): User
	{
		return $this->service($this->legacyMe())->create(new User(0, 1, 'mvc_test_'.uniqid(), lastname: $lastname), 'Secret-Pass-123!', []);
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private function post(int $id, array $post): Request
	{
		return $this->request('POST', [], $post + ['token' => 'checked-by-main-inc'], $id);
	}

	/**
	 * @param array<string, mixed> $get
	 * @param array<string, mixed> $post
	 */
	private function request(string $method, array $get, array $post, int $id): Request
	{
		$_GET = $get;
		$_POST = $post;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI'] = '/user/'.$id.'/rights';

		return Request::fromGlobals('')->withInputFilter(new LegacyInputFilter());
	}

	private function legacyMe(): CurrentUser
	{
		return new LegacyCurrentUser($this->ctx->user);
	}

	private function repo(): UserRepository
	{
		return new UserRepository($this->connection, (int) $this->ctx->conf->entity);
	}

	private function service(CurrentUser $me): UserService
	{
		$writer = new LegacyUserWriter($this->ctx, new LegacyObjectLoader($this->connection), $this->connection);

		return new UserService($this->repo(), $writer, new EventDispatcher(), $this->connection, $me);
	}

	private function controller(?CurrentUser $me = null, ?ScriptedHooks $scripted = null, bool $advancedPerms = false): UserRightsController
	{
		$ctx = $this->ctx;
		$me ??= $this->legacyMe();
		$config = new LegacyConfig($ctx);
		if ($advancedPerms) {
			$this->setConf('MAIN_USE_ADVANCED_PERMS', 1);
		}
		$perms = new UserPermissionChecker($me, $advancedPerms, false, false, [], false, false, (int) $ctx->conf->entity);
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class, UserListController::class, UserRightsController::class]), '');
		$hm = $ctx->hookmanager;
		if ($scripted !== null) {
			$hm = new \HookManager($ctx->db);
			ScriptedHooks::register($hm, 'userperms', $scripted);
		}
		$chrome = new RightsChrome($ctx, $this->connection);

		return new UserRightsController(
			$this->repo(),
			$perms,
			$me,
			new LegacyHooks($hm),
			new LegacyObjectLoader($this->connection),
			new LegacyTranslator($ctx->langs),
			new UserCardChrome($ctx, $urls),
			$chrome,
			new RightsRepository($this->connection, $chrome, $config),
			$this->service($me),
			new FlashBag(),
			$config,
			$urls
		);
	}
}
