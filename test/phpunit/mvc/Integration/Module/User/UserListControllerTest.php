<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Config;
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
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Core\View\LatteRenderer;
use Dolibarr\Core\View\ViewModel;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Controller\UserListController;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Entity\UserView;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\View\UserListChrome;
use Dolibarr\Tests\Integration\Fixture\LimitUsersConfig;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

/**
 * The user list on /user (htdocs/user/list.php). Every test runs in a transaction rolled back in tearDown.
 */
final class UserListControllerTest extends TestCase
{
	use OverridesLegacyConf;

	private LegacyContext $ctx;

	private Connection $connection;

	private ?\stdClass $browser = null;

	private mixed $selectedFields = null;

	/** @var array<mixed> */
	private array $session = [];

	/** @var array<mixed> */
	private array $get = [];

	/** @var array<mixed> */
	private array $post = [];

	/** @var array<mixed> */
	private array $server = [];

	protected function setUp(): void
	{
		$this->get = $_GET;
		$this->post = $_POST;
		$this->server = $_SERVER;
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
		// print_barre_liste() reads the browser name, set by main.inc.php from the user agent
		if (!isset($conf->browser->name)) {
			$conf->browser->name = 'firefox';
		}
		$this->selectedFields = $this->ctx->user?->conf->MAIN_SELECTEDFIELDS_userlist ?? null;
		$this->disableUserAgendaEvents();
		$this->ctx->langs->loadLangs(['main', 'users', 'errors']);
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
		$this->restoreConf();
		$user = $this->ctx->user;
		if ($user !== null) {
			if ($this->selectedFields === null) {
				unset($user->conf->MAIN_SELECTEDFIELDS_userlist);
			} else {
				$user->conf->MAIN_SELECTEDFIELDS_userlist = $this->selectedFields;
			}
		}
		$this->ctx->conf->browser = $this->browser;
		$_SESSION = $this->session;
		$_GET = $this->get;
		$_POST = $this->post;
		$_SERVER = $this->server;
	}

	public function testListJsonExposesItemsAndTotal(): void
	{
		$a = $this->disposable('Alpha');
		$this->disposable('Beta');

		$vm = $this->list('GET', ['search_lastname' => 'mvc_list_'], [], true);

		self::assertInstanceOf(ViewModel::class, $vm);
		self::assertSame(['items', 'total', 'page', 'limit'], array_keys($vm->exposed));
		self::assertSame(2, $vm->exposed['total']);
		self::assertSame(0, $vm->exposed['page']);
		$items = $vm->exposed['items'];
		self::assertIsArray($items);
		self::assertCount(2, $items);
		self::assertInstanceOf(UserView::class, $items[0]);
		$first = $items[0]->toArray();
		self::assertSame($a->login, $first['login']);
		self::assertArrayNotHasKey('pass', $first);
		self::assertArrayNotHasKey('api_key', $first);
		self::assertArrayNotHasKey('apiKey', $first);
	}

	public function testListHtmlStructure(): void
	{
		$hostile = $this->disposable('mvc_list_<b>x</b>');

		ob_start();
		try {
			$vm = $this->list('GET', ['search_lastname' => 'mvc_list_']);
		} finally {
			$printed = ob_get_clean();
		}

		self::assertSame('', $printed);
		self::assertInstanceOf(ViewModel::class, $vm);
		self::assertSame('user:user_list', $vm->template);
		$titleBar = (string) $vm->data['titleBarHtml'];
		// print_barre_liste() with the New user button to the user_new route and the view buttons to the list
		self::assertStringContainsString('href="/user/new?leftmenu="', $titleBar);
		self::assertStringContainsString('/user?mode=kanban', $titleBar);
		self::assertStringContainsString('name="massaction"', $titleBar);
		self::assertStringContainsString('value="disable"', $titleBar);
		$rows = $vm->data['rows'];
		self::assertIsArray($rows);
		self::assertCount(1, $rows);
		// getNomUrl() links to the user_show route
		$login = (string) $rows[0]['cells']['login'];
		self::assertStringContainsString('href="/user/'.$hostile->id.'?save_lastsearch_values=1"', $login);
		self::assertStringNotContainsString('card.php', $login);
		$titles = implode('', $vm->data['titlesBefore']);
		self::assertStringContainsString('sortfield=u.login', $titles);
		self::assertStringContainsString('class="multiselectpicto"', $titles.implode('', $vm->data['titlesAfter']));

		// The template renders the page: the hostile last name escaped, the row linking to the card route
		$html = $this->render($vm);
		self::assertStringContainsString('<form method="POST" id="searchFormList" action="/user">', $html);
		self::assertStringContainsString('<tr data-rowid="'.$hostile->id.'" class="oddeven row-with-select">', $html);
		self::assertStringContainsString('mvc_list_&lt;b&gt;x&lt;/b&gt;', $html);
		self::assertStringNotContainsString('mvc_list_<b>x</b>', $html);
		self::assertStringContainsString('name="search_lastname" class="maxwidth50" value="mvc_list_"', $html);
	}

	public function testMassDisableRefusesSelfAndAdmins(): void
	{
		$target = $this->disposable('Target');
		$admin = $this->disposable('Admin', true);
		$me = $this->ctx->user;
		self::assertNotNull($me);

		// list.php l.325-329: oneself stops everything, nothing is written
		$response = $this->list('POST', [], $this->mass('disable', [$target->id, (int) $me->id]));
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame(1, $this->repo()->find($target->id)?->status);
		self::assertSame([$this->trans('CantDisableYourself')], $_SESSION['dol_events']['errors'] ?? []);

		// l.333-337: an admin
		$_SESSION = [];
		$this->list('POST', [], $this->mass('disable', [$target->id, $admin->id]));
		self::assertSame(1, $this->repo()->find($target->id)?->status);
		self::assertSame(1, $this->repo()->find($admin->id)?->status);
		self::assertSame([$this->trans('CantDisableAnAdminUserWithMassActions', $admin->login)], $_SESSION['dol_events']['errors'] ?? []);

		// Without confirmmassaction (l.262-264), nothing either
		$_SESSION = [];
		$this->list('POST', [], ['massaction' => 'disable', 'toselect' => [(string) $target->id], 'token' => 'checked-by-main-inc']);
		self::assertSame(1, $this->repo()->find($target->id)?->status);

		// Allowed: disabled, RecordsModified
		$_SESSION = [];
		$response = $this->list('POST', [], $this->mass('disable', [$target->id]));
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringStartsWith('/user', $response->headers()['Location']);
		self::assertSame(0, $this->repo()->find($target->id)?->status);
		self::assertSame([$this->trans('RecordsModified', '1')], $_SESSION['dol_events']['mesgs'] ?? []);

		// Reactivate, refused when the quota is reached (l.356-362)
		$_SESSION = [];
		$this->list('POST', [], $this->mass('reactivate', [$target->id]), false, new LimitUsersConfig(new LegacyConfig($this->ctx), $this->repo()->countForQuota() + 1));
		self::assertSame(0, $this->repo()->find($target->id)?->status);
		self::assertSame([$this->trans('YourQuotaOfUsersIsReached')], $_SESSION['dol_events']['errors'] ?? []);

		$_SESSION = [];
		$this->list('POST', [], $this->mass('reactivate', [$target->id]));
		self::assertSame(1, $this->repo()->find($target->id)?->status);
	}

	public function testMassActionNeedsTheRight(): void
	{
		$target = $this->disposable('Target');
		$actor = $this->disposable('Actor');

		$this->list('POST', [], $this->mass('disable', [$target->id]), false, null, new RightsCurrentUser($actor->id, false, ['user.user.read']));

		self::assertSame(1, $this->repo()->find($target->id)?->status);
	}

	public function testListNeedsTheReadRight(): void
	{
		$actor = $this->disposable('Actor');

		$this->expectException(AccessDeniedException::class);
		$this->list('GET', [], [], false, null, new RightsCurrentUser($actor->id, false, []));
	}

	public function testInvalidTokenOnMassActionWritesNothing(): void
	{
		$target = $this->disposable('Target');

		// main.inc.php on an invalid token: $_POST emptied, massaction and confirmmassaction removed from $_GET
		$vm = $this->list('POST', ['errorcode' => 'InvalidToken', 'toselect' => [(string) $target->id]], []);

		self::assertInstanceOf(ViewModel::class, $vm);
		self::assertSame(1, $this->repo()->find($target->id)?->status);
	}

	public function testSelectedFieldsAreSaved(): void
	{
		$response = $this->list('POST', [], ['formfilteraction' => 'listafterchangingselectedfields', 'selectedfields' => 'u.login,u.email,', 'search_login' => 'adm', 'token' => 'checked-by-main-inc']);

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user?contextpage=userlist&search_login=adm&search_status=1', $response->headers()['Location']);
		$me = $this->ctx->user;
		self::assertNotNull($me);
		$row = $this->connection->fetchOne('SELECT value FROM '.MAIN_DB_PREFIX."user_param WHERE fk_user = ".(int) $me->id." AND param = 'MAIN_SELECTEDFIELDS_userlist'");
		self::assertSame('u.login,u.email,', $row?->value ?? null);

		// The next display shows only these columns (Form::multiSelectArrayWithCheckbox())
		$vm = $this->list('GET', ['search_login' => 'admin']);
		self::assertInstanceOf(ViewModel::class, $vm);
		self::assertTrue($vm->data['show']['u.email']);
		self::assertFalse($vm->data['show']['u.lastname']);
	}

	public function testTagMassActionIsForwardedToListPhp(): void
	{
		$target = $this->disposable('Target');

		$response = $this->list('POST', [], $this->mass('presetsupervisor', [$target->id]));

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringStartsWith(DOL_URL_ROOT.'/user/list.php?massaction=presetsupervisor&confirmmassaction=1&toselect%5B%5D='.$target->id, $response->headers()['Location']);
	}

	private function render(ViewModel $vm): string
	{
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class, UserListController::class]), '');
		$renderer = new LatteRenderer(
			sys_get_temp_dir().'/latte-test-'.getmypid(),
			['core' => DOL_DOCUMENT_ROOT.'/src/Core/templates', 'user' => DOL_DOCUMENT_ROOT.'/src/Module/User/templates'],
			new DolibarrExtension(new LegacyTranslator($this->ctx->langs), $this->legacyMe(), $urls),
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

		return $renderer->render($vm->template, ['layout' => $layout, ...$vm->templateData()]);
	}

	/**
	 * @param list<int> $ids
	 * @return array<string, mixed>
	 */
	private function mass(string $action, array $ids): array
	{
		return ['massaction' => $action, 'confirmmassaction' => 'Confirm', 'toselect' => array_map('strval', $ids), 'token' => 'checked-by-main-inc'];
	}

	private function trans(string $key, string ...$args): string
	{
		return (new LegacyTranslator($this->ctx->langs))->trans($key, ...$args);
	}

	private function disposable(string $lastname, bool $admin = false): User
	{
		$service = new UserService($this->repo(), $this->writer(), new EventDispatcher(), $this->connection, $this->legacyMe());

		return $service->create(new User(0, 1, 'mvc_test_'.uniqid(), admin: $admin, lastname: str_starts_with($lastname, 'mvc_list_') ? $lastname : 'mvc_list_'.$lastname), 'Secret-Pass-123!', []);
	}

	/**
	 * @param array<string, mixed> $get
	 * @param array<string, mixed> $post
	 */
	private function list(string $method, array $get, array $post = [], bool $json = false, ?Config $config = null, ?CurrentUser $me = null): mixed
	{
		$_GET = $get;
		$_POST = $post;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI'] = '/user';
		$_SERVER['PHP_SELF'] = '/user';
		if ($json) {
			$_SERVER['HTTP_ACCEPT'] = 'application/json';
		} else {
			unset($_SERVER['HTTP_ACCEPT']);
		}
		$request = Request::fromGlobals('')->withInputFilter(new LegacyInputFilter());

		return $this->controller($config, $me)->list($request);
	}

	private function legacyMe(): CurrentUser
	{
		$legacyUser = $this->ctx->user;
		self::assertNotNull($legacyUser);

		return new LegacyCurrentUser($legacyUser);
	}

	private function controller(?Config $config = null, ?CurrentUser $me = null): UserListController
	{
		$ctx = $this->ctx;
		$me ??= $this->legacyMe();
		$config ??= new LegacyConfig($ctx);
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class, UserListController::class]), '');

		return new UserListController(
			$this->repo(),
			new UserPermissionChecker($me, false, false, false, [$me->id()], false, false, (int) $ctx->conf->entity),
			$me,
			new LegacyHooks($ctx->hookmanager),
			new LegacyTranslator($ctx->langs),
			new UserListChrome($ctx, $urls),
			new FlashBag(),
			new UserService($this->repo(), $this->writer(), new EventDispatcher(), $this->connection, $me),
			$config,
			$urls
		);
	}

	private function writer(): LegacyUserWriter
	{
		return new LegacyUserWriter($this->ctx, new LegacyObjectLoader($this->connection), $this->connection);
	}

	private function repo(): UserRepository
	{
		return new UserRepository($this->connection, (int) $this->ctx->conf->entity);
	}
}
