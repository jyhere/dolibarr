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
use Dolibarr\Core\View\ViewModel;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\Service\UserValidator;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Module\User\View\UserFormChrome;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

final class UserCreateTest extends TestCase
{
	use OverridesLegacyConf;

	private LegacyContext $ctx;

	private Connection $connection;

	private ?\stdClass $browser = null;

	/** @var array<mixed> */
	private array $session = [];

	private mixed $mainUrlRoot = null;

	/** @var array<mixed> */
	private array $get = [];

	/** @var array<mixed> */
	private array $post = [];

	protected function setUp(): void
	{
		$this->get = $_GET;
		$this->post = $_POST;
		$this->session = $_SESSION ?? [];
		$_SESSION = [];
		$this->ctx = LegacyContext::fromGlobals();
		$this->connection = new Connection($this->ctx->db);
		// Browser detection is done by main.inc.php, not by master.inc.php used here: the form helpers read it.
		$conf = $this->ctx->conf;
		$this->browser = $conf->browser;
		$conf->browser = clone $conf->browser;
		if (!isset($conf->browser->layout)) {
			$conf->browser->layout = 'classic';
		}
		// conf.php is included by the bootstrap in a function scope: acceptLocalLinktoMedia() reads this global
		$this->mainUrlRoot = $GLOBALS['dolibarr_main_url_root'] ?? null;
		$GLOBALS['dolibarr_main_url_root'] ??= 'http://localhost';
		$this->disableUserAgendaEvents();
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
		$this->restoreConf();
		$GLOBALS['dolibarr_main_url_root'] = $this->mainUrlRoot;
		$this->ctx->conf->browser = $this->browser;
		$_SESSION = $this->session;
		$_GET = $this->get;
		$_POST = $this->post;
	}

	public function testCreateFormIsForbiddenWithoutRight(): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->controller(new RightsCurrentUser(999999, false, ['user.user.read']))->createForm($this->request('GET', [], []));
	}

	public function testCreateFormShowsTheCardDefaults(): void
	{
		ob_start();
		try {
			$vm = $this->controller()->createForm($this->request('GET', ['backtopage' => '/user/list.php'], []));
		} finally {
			$printed = ob_get_clean();
		}
		self::assertSame('', $printed);

		self::assertSame('user:user_form', $vm->template);
		self::assertSame('create', $vm->data['mode']);
		$values = $vm->data['values'];
		self::assertInstanceOf(User::class, $values);
		self::assertTrue($values->employee);
		self::assertNull($values->dateStartValidity);
		self::assertSame('/user/list.php', $vm->data['backtopage']);
		self::assertSame((int) $this->ctx->conf->entity, $vm->data['entity']);
		$f = $vm->data['f'];
		self::assertIsArray($f);
		self::assertStringContainsString('name="fk_user"', $f['supervisor']);
		self::assertStringContainsString('id="password"', $f['passwordField']);
		self::assertStringContainsString('name="datestartvalidityday"', $f['dateStartValidity']);
		self::assertStringContainsString('name="save"', $f['buttons']);
	}

	public function testCreatePersistsAndRedirectsToCard(): void
	{
		$login = 'mvc_test_'.uniqid();

		$response = $this->controller()->create($this->request('POST', [], [
			'lastname' => 'Created',
			'firstname' => 'Mvc',
			'login' => $login,
			'password' => 'Secret-Pass-123!',
			'employee' => '1',
			'entity' => '1',
		]));

		$created = $this->repo()->findByLogin($login);
		self::assertNotNull($created);
		self::assertSame('Created', $created->lastname);
		self::assertTrue($created->employee);
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame(302, $response->status());
		self::assertSame('/user/'.$created->id, $response->headers()['Location']);
		$row = $this->connection->fetchOne('SELECT pass_crypted FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$created->id);
		self::assertTrue(dol_verifyHash('Secret-Pass-123!', (string) $row?->pass_crypted));
		self::assertArrayNotHasKey('errors', $_SESSION['dol_events'] ?? []);
	}

	public function testCreateRedirectsToSafeBacktopageWithTheNewId(): void
	{
		$login = 'mvc_test_'.uniqid();
		$response = $this->controller()->create($this->request('POST', [], [
			'lastname' => 'Back', 'login' => $login, 'backtopage' => '/user/perms.php?id=__ID__',
		]));
		$created = $this->repo()->findByLogin($login);
		self::assertNotNull($created);
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/perms.php?id='.$created->id, $response->headers()['Location']);

		$response = $this->controller()->create($this->request('POST', [], [
			'lastname' => 'Back', 'login' => $login.'b', 'backtopage' => '//evil.example/x',
		]));
		$created = $this->repo()->findByLogin($login.'b');
		self::assertNotNull($created);
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/'.$created->id, $response->headers()['Location']);
	}

	public function testCancelRedirectsWithoutWriting(): void
	{
		$login = 'mvc_test_'.uniqid();
		$response = $this->controller()->create($this->request('POST', [], [
			'lastname' => 'Cancel', 'login' => $login, 'cancel' => 'Cancel', 'backtopageforcancel' => '/user/list.php?x=1',
		]));
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/list.php?x=1', $response->headers()['Location']);
		self::assertNull($this->repo()->findByLogin($login));

		$response = $this->controller()->create($this->request('POST', [], [
			'lastname' => 'Cancel', 'login' => $login, 'cancel' => 'Cancel', 'backtopage' => 'https://evil.example',
		]));
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/list.php', $response->headers()['Location']);
	}

	public function testDuplicateLoginRedisplaysFormWithError(): void
	{
		$before = $this->countUsers();
		$existing = (string) $this->ctx->user?->login;

		ob_start();
		try {
			$response = $this->controller()->create($this->request('POST', [], [
				'lastname' => 'Duplicate', 'login' => $existing, 'password' => 'Secret-Pass-123!', 'job' => '<b>Job</b>',
			]));
		} finally {
			$printed = ob_get_clean();
		}
		self::assertSame('', $printed);

		self::assertInstanceOf(ViewModel::class, $response);
		self::assertSame('user:user_form', $response->template);
		self::assertSame(200, $response->status);
		$values = $response->data['values'];
		self::assertInstanceOf(User::class, $values);
		self::assertSame('Duplicate', $values->lastname);
		self::assertSame($existing, $values->login);
		$errors = $_SESSION['dol_events']['errors'] ?? [];
		self::assertCount(1, $errors);
		$this->ctx->langs->load('errors');
		self::assertSame($this->ctx->langs->trans('ErrorLoginAlreadyExists', $existing), $errors[0]);
		self::assertSame($before, $this->countUsers());
		self::assertSame(1, (int) $this->ctx->db->transaction_opened, 'The service transaction is closed');
	}

	public function testMissingFieldsAreFlashedOnceEach(): void
	{
		$response = $this->controller()->create($this->request('POST', [], ['firstname' => 'Only']));

		self::assertInstanceOf(ViewModel::class, $response);
		$this->ctx->langs->load('errors');
		self::assertSame([$this->ctx->langs->transnoentities('NameNotDefined'), $this->ctx->langs->transnoentities('LoginNotDefined')], $_SESSION['dol_events']['errors']);
	}

	public function testInvalidTokenWritesNothing(): void
	{
		$before = $this->countUsers();

		// main.inc.php on an invalid token: $_POST emptied, errorcode set in $_GET
		$response = $this->controller()->create($this->request('POST', ['errorcode' => 'InvalidToken'], []));

		self::assertInstanceOf(ViewModel::class, $response);
		self::assertSame('user:user_form', $response->template);
		self::assertSame($before, $this->countUsers());
		self::assertArrayNotHasKey('errors', $_SESSION['dol_events'] ?? []);
	}

	/**
	 * @param array<string, mixed> $get
	 * @param array<string, mixed> $post
	 */
	private function request(string $method, array $get, array $post): Request
	{
		$_GET = $get;
		$_POST = $post;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI'] = '/user/new';

		return Request::fromGlobals('')->withInputFilter(new LegacyInputFilter());
	}

	private function controller(?CurrentUser $me = null): UserController
	{
		$ctx = $this->ctx;
		$legacyUser = $ctx->user;
		self::assertNotNull($legacyUser);
		$me ??= new LegacyCurrentUser($legacyUser);
		$config = new LegacyConfig($ctx);
		$perms = new UserPermissionChecker($me, false, false, false, [], false, false, (int) $ctx->conf->entity);
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class]), '');
		$loader = new LegacyObjectLoader($this->connection);
		$translator = new LegacyTranslator($ctx->langs);

		return new UserController(
			$this->repo(),
			$perms,
			$me,
			new LegacyHooks($ctx->hookmanager),
			$loader,
			$translator,
			new UserCardChrome($ctx, $urls),
			new FlashBag(),
			new UserService($this->repo(), new LegacyUserWriter($ctx, $loader, $this->connection), new EventDispatcher(), $this->connection, $me),
			new UserValidator($translator, $config),
			new UserFormChrome($ctx),
			$config,
			$urls
		);
	}

	private function repo(): UserRepository
	{
		return new UserRepository($this->connection, (int) $this->ctx->conf->entity);
	}

	private function countUsers(): int
	{
		$row = $this->connection->fetchOne('SELECT COUNT(rowid) as nb FROM '.MAIN_DB_PREFIX.'user');

		return (int) ($row?->nb ?? 0);
	}
}
