<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\Notes;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Entity\EntityRegistry;
use Dolibarr\Core\Entity\EntityServices;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyCurrentUser;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyInputFilter;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTranslator;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Logger;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Module\Notes\Controller\NotesController;
use Dolibarr\Module\Notes\Event\NoteUpdated;
use Dolibarr\Module\Notes\Listener\NoteTriggerBridge;
use Dolibarr\Module\Notes\Listener\NoteTriggerException;
use Dolibarr\Module\Notes\Repository\NotesRepository;
use Dolibarr\Module\Notes\View\NotesChrome;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Controller\UserListController;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\UserModule;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use Dolibarr\Tests\Integration\Fixture\ScriptedHooks;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

/**
 * The notes component on the user entity (htdocs/user/note.php, core/actions_setnotes.inc.php).
 * Every test runs in a transaction rolled back in tearDown; the disposable users are created inside it.
 */
final class NotesControllerTest extends TestCase
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

	private mixed $phpSelf = null;

	protected function setUp(): void
	{
		$this->get = $_GET;
		$this->post = $_POST;
		$this->session = $_SESSION ?? [];
		$this->phpSelf = $_SERVER['PHP_SELF'] ?? null;
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
		$this->ctx->langs->loadLangs(['main', 'users', 'errors']);
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
		$_SERVER['PHP_SELF'] = $this->phpSelf;
	}

	public function testUpdatePublicNoteWritesAndFiresUserModify(): void
	{
		$target = $this->disposable('Target');
		$this->setConf('MAIN_LOGEVENTS_USER_MODIFY', 1);
		$before = $this->countEvents('USER_MODIFY');
		$spied = [];
		$dispatcher = $this->dispatcher();
		$dispatcher->addListener(NoteUpdated::class, static function (NoteUpdated $event) use (&$spied): void {
			$spied[] = $event;
		});

		$response = $this->controller(null, null, $dispatcher)->update($this->post($target->id, ['action' => 'setnote_public', 'note_public' => 'Hello &amp; <b>world</b>']), 'user', $target->id);

		$this->assertRedirectsToNotes($response, $target->id);
		$row = $this->row($target->id);
		// GETPOST('restricthtml') then dol_html_entity_decode(..., keepsomeentities 1) of actions_setnotes.inc.php: &amp; is kept
		self::assertSame('Hello &amp; <b>world</b>', $row->note_public);
		self::assertSame((int) $this->ctx->user?->id, (int) $row->fk_user_modif);
		self::assertCount(1, $spied);
		self::assertSame(['user', 'user', $target->id, 'note_public'], [$spied[0]->element, $spied[0]->tableElement, $spied[0]->id, $spied[0]->field]);
		// update_note() runs USER_MODIFY: the security event is logged
		self::assertSame($before + 1, $this->countEvents('USER_MODIFY'));
		self::assertSame([], $_SESSION['dol_events'] ?? []);
	}

	public function testPrivateNoteForbiddenForExternalUser(): void
	{
		$actor = $this->disposable('External');
		$this->connection->execute('UPDATE '.MAIN_DB_PREFIX."user SET note_private = 'secret' WHERE rowid = ".$actor->id);
		// External (company 7) with the right to edit his own notes
		$me = new RightsCurrentUser($actor->id, false, ['user.self.write'], 7);

		// actions_setnotes.inc.php l.92: setnote_private only when empty($user->socid)
		$response = $this->controller($me)->update($this->post($actor->id, ['action' => 'setnote_private', 'note_private' => 'changed']), 'user', $actor->id);
		$this->assertRedirectsToNotes($response, $actor->id);
		self::assertSame('secret', $this->row($actor->id)->note_private);

		// The public note stays editable
		$this->controller($me)->update($this->post($actor->id, ['action' => 'setnote_public', 'note_public' => 'pub']), 'user', $actor->id);
		self::assertSame('pub', $this->row($actor->id)->note_public);

		// notes.tpl.php l.149: the private row is hidden, and its value never exposed
		$view = $this->controller($me)->show($this->request('GET', [], [], $actor->id), 'user', $actor->id);
		self::assertNull($view->data['fragments']['privateKey']);
		self::assertNull($view->data['fragments']['privateVal']);
		self::assertArrayNotHasKey('notePrivate', $view->exposed);
		self::assertSame('pub', $view->exposed['notePublic']);
	}

	public function testUnknownEntityIs404(): void
	{
		try {
			$this->controller()->show($this->request('GET', [], [], 1), 'nothing', 1);
			self::fail('NotFoundException expected');
		} catch (NotFoundException) {
			self::addToAssertionCount(1);
		}

		$this->expectException(NotFoundException::class);
		$this->controller()->update($this->post(1, ['action' => 'setnote_public', 'note_public' => 'x']), 'nothing', 1);
	}

	public function testUnknownIdIs404(): void
	{
		$this->expectException(NotFoundException::class);
		$this->controller()->show($this->request('GET', [], [], 999999), 'user', 999999);
	}

	public function testScriptTagIsStrippedByRestricthtml(): void
	{
		$target = $this->disposable('Target');

		$this->controller()->update($this->post($target->id, ['action' => 'setnote_public', 'note_public' => '<b>ok</b><script>alert(1)</script><img src=x onerror=alert(2)>']), 'user', $target->id);

		$note = (string) $this->row($target->id)->note_public;
		self::assertStringContainsString('<b>ok</b>', $note);
		self::assertStringNotContainsStringIgnoringCase('<script', $note);
		self::assertStringNotContainsStringIgnoringCase('onerror', $note);
	}

	public function testAdminWithUserWriteCanEditOthersNotes(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');
		// Admin with user->user->write but without user->self->write: notes.tpl.php l.121-122 would hide the edit
		// pencil (permission forced to user->self->write); canEditNotes() gives it, and the write goes through.
		$me = new RightsCurrentUser($actor->id, true, ['user.user.read', 'user.user.write']);

		$view = $this->controller($me)->show($this->request('GET', [], [], $target->id), 'user', $target->id);
		self::assertTrue($view->data['permissionNote']);
		self::assertStringContainsString('action=editnote_public', $view->data['fragments']['publicKey']);
		self::assertStringContainsString('action=editnote_private', (string) $view->data['fragments']['privateKey']);

		$response = $this->controller($me)->update($this->post($target->id, ['action' => 'setnote_public', 'note_public' => 'by admin']), 'user', $target->id);
		$this->assertRedirectsToNotes($response, $target->id);
		self::assertSame('by admin', $this->row($target->id)->note_public);
		self::assertSame($actor->id, (int) $this->row($target->id)->fk_user_modif);
	}

	public function testWithoutEditRightIsForbiddenAndHasNoPencil(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');
		$me = new RightsCurrentUser($actor->id, false, ['user.user.read', 'user.self.write']);

		$view = $this->controller($me)->show($this->request('GET', [], [], $target->id), 'user', $target->id);
		self::assertFalse($view->data['permissionNote']);
		self::assertStringNotContainsString('editnote_public', $view->data['fragments']['publicKey']);

		$this->expectException(AccessDeniedException::class);
		$this->controller($me)->update($this->post($target->id, ['action' => 'setnote_public', 'note_public' => 'x']), 'user', $target->id);
	}

	public function testUnreadableIsForbidden(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');

		$this->expectException(AccessDeniedException::class);
		$this->controller(new RightsCurrentUser($actor->id))->show($this->request('GET', [], [], $target->id), 'user', $target->id);
	}

	public function testShowRendersTheNotesAndEditMode(): void
	{
		$target = $this->disposable('Target');
		$this->connection->execute('UPDATE '.MAIN_DB_PREFIX."user SET note_public = '<b>pub</b>', note_private = 'priv' WHERE rowid = ".$target->id);
		$_SERVER['PHP_SELF'] = '/user/'.$target->id.'/note';

		$view = $this->controller()->show($this->request('GET', [], [], $target->id), 'user', $target->id);
		self::assertSame('notes:notes', $view->template);
		self::assertSame(['notePublic' => '<b>pub</b>', 'notePrivate' => 'priv'], $view->exposed);
		self::assertStringContainsString('<b>pub</b>', $view->data['fragments']['publicVal']);
		self::assertStringContainsString('/user/'.$target->id.'/note?action=editnote_public', $view->data['fragments']['publicKey']);
		// Tabs: the note tab is active and points to the route, with the badge of user_prepare_head()
		self::assertMatchesRegularExpression('#<a[^>]+id="note"[^>]+href="/user/'.$target->id.'/note"|href="/user/'.$target->id.'/note"[^>]*id="note"#', $view->data['chrome']['tabsHtml']);
		self::assertStringContainsString('<span class="badge marginleftonlyshort">2</span>', $view->data['chrome']['tabsHtml']);
		self::assertStringContainsString('href="/user/'.$target->id.'"', $view->data['chrome']['tabsHtml']);
		self::assertStringContainsString('<td class="titlefield">', $view->data['chrome']['headerRowsHtml']);

		// Edit mode: the textarea of the public note, its form posting back to the route
		$view = $this->controller()->show($this->request('GET', ['action' => 'editnote_public'], [], $target->id), 'user', $target->id);
		$val = $view->data['fragments']['publicVal'];
		self::assertStringContainsString('<form method="post" action="/user/'.$target->id.'/note">', $val);
		self::assertStringContainsString('<input type="hidden" name="action" value="setnote_public">', $val);
		self::assertStringContainsString('<textarea id="note_public" name="note_public"', $val);
		self::assertSame('editnote_public', $view->data['action']);
		self::assertStringNotContainsString('<textarea', (string) $view->data['fragments']['privateVal']);
	}

	public function testAutoTimestampIsPrependedInEditMode(): void
	{
		$target = $this->disposable('Target');
		$this->setConf('MAIN_AUTO_TIMESTAMP_IN_PUBLIC_NOTES', 1);

		$view = $this->controller()->show($this->request('GET', ['action' => 'editnote_public'], [], $target->id), 'user', $target->id);

		self::assertStringContainsString('-- '.dol_print_date(dol_now(), 'dayhour'), $view->data['fragments']['publicVal']);
	}

	public function testShowNeverWrites(): void
	{
		$target = $this->disposable('Target');

		$this->controller()->show($this->request('GET', ['action' => 'setnote_public', 'note_public' => 'via get'], [], $target->id), 'user', $target->id);

		self::assertSame('', (string) $this->row($target->id)->note_public);
	}

	public function testInvalidTokenWritesNothing(): void
	{
		$target = $this->disposable('Target');
		$hooks = new ScriptedHooks([]);

		// main.inc.php on an invalid token: $_POST emptied, action removed from $_GET, errorcode set
		$response = $this->controller(null, $hooks)->update($this->request('POST', ['errorcode' => 'InvalidToken'], ['note_public' => 'x'], $target->id), 'user', $target->id);

		$this->assertRedirectsToNotes($response, $target->id);
		self::assertSame('', $hooks->actions['doActions']);
		self::assertSame('', (string) $this->row($target->id)->note_public);
	}

	public function testDoActionsReceivesTheActionAndCanVeto(): void
	{
		$target = $this->disposable('Target');
		$hooks = new ScriptedHooks(['doActions' => ['code' => -1, 'errors' => ['Hook says no']]]);

		$response = $this->controller(null, $hooks)->update($this->post($target->id, ['action' => 'setnote_public', 'note_public' => 'x']), 'user', $target->id);

		$this->assertRedirectsToNotes($response, $target->id);
		self::assertSame('setnote_public', $hooks->actions['doActions']);
		// note.php l.83: array('id' => $socid)
		self::assertSame(0, $hooks->parameters['doActions']['id'] ?? null);
		self::assertSame('', (string) $this->row($target->id)->note_public);
		self::assertSame(['Hook says no'], $_SESSION['dol_events']['errors'] ?? []);
	}

	public function testCancelWritesNothing(): void
	{
		$target = $this->disposable('Target');

		$response = $this->controller()->update($this->post($target->id, ['action' => 'setnote_public', 'note_public' => 'x', 'cancel' => 'Cancel']), 'user', $target->id);

		$this->assertRedirectsToNotes($response, $target->id);
		self::assertSame('', (string) $this->row($target->id)->note_public);
	}

	public function testFailingTriggerIsFlashed(): void
	{
		$target = $this->disposable('Target');
		$dispatcher = new EventDispatcher();
		$dispatcher->addListener(NoteUpdated::class, static function (): void {
			throw new NoteTriggerException(['Trigger says no']);
		});

		$response = $this->controller(null, null, $dispatcher)->update($this->post($target->id, ['action' => 'setnote_public', 'note_public' => 'x']), 'user', $target->id);

		// The write is rolled back with the transaction of the controller (nested here in the one of the test)
		$this->assertRedirectsToNotes($response, $target->id);
		self::assertSame(['Trigger says no'], $_SESSION['dol_events']['errors'] ?? []);
	}

	public function testFailingRollbackChainsTheOriginalException(): void
	{
		$target = $this->disposable('Target');
		$dispatcher = new EventDispatcher();
		$dispatcher->addListener(NoteUpdated::class, static function (): void {
			throw new \RuntimeException('listener failed');
		});
		$db = new class extends \DoliDBMysqli {
			public function __construct()
			{
			}

			public function begin($textinlog = '')
			{
				return 1;
			}

			public function rollback($log = '')
			{
				$this->lasterror = 'forced rollback failure';

				return false;
			}
		};

		try {
			$this->controller(null, null, $dispatcher, new Connection($db))->update($this->post($target->id, ['action' => 'setnote_public', 'note_public' => 'x']), 'user', $target->id);
			self::fail('DatabaseException expected');
		} catch (DatabaseException $e) {
			self::assertStringContainsString('forced rollback failure', $e->getMessage());
			self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
			self::assertSame('listener failed', $e->getPrevious()->getMessage());
		}
	}

	public function testFailingRollbackAfterATriggerFailureChainsTheTriggerException(): void
	{
		$target = $this->disposable('Target');
		$dispatcher = new EventDispatcher();
		$dispatcher->addListener(NoteUpdated::class, static function (): void {
			throw new NoteTriggerException(['Trigger says no']);
		});
		$db = new class extends \DoliDBMysqli {
			public function __construct()
			{
			}

			public function begin($textinlog = '')
			{
				return 1;
			}

			public function rollback($log = '')
			{
				$this->lasterror = 'forced rollback failure';

				return false;
			}
		};

		try {
			$this->controller(null, null, $dispatcher, new Connection($db))->update($this->post($target->id, ['action' => 'setnote_public', 'note_public' => 'x']), 'user', $target->id);
			self::fail('DatabaseException expected');
		} catch (DatabaseException $e) {
			self::assertInstanceOf(NoteTriggerException::class, $e->getPrevious());
		}
	}

	private function assertRedirectsToNotes(mixed $response, int $id): void
	{
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/'.$id.'/note', $response->headers()['Location']);
	}

	private function countEvents(string $type): int
	{
		return (int) $this->connection->fetchValue('SELECT COUNT(*) AS nb FROM @events WHERE type = :type', ['type' => $type]);
	}

	private function row(int $id): object
	{
		$row = $this->connection->fetchOne('SELECT note_public, note_private, fk_user_modif FROM @user WHERE rowid = :id', ['id' => $id]);
		self::assertNotNull($row);

		return (object) $row;
	}

	private function disposable(string $lastname): User
	{
		$loader = new LegacyObjectLoader($this->connection);
		$service = new UserService($this->repo(), new LegacyUserWriter($this->ctx, $loader, $this->connection), new EventDispatcher(), $this->connection, $this->legacyMe());

		return $service->create(new User(0, 1, 'mvc_test_'.uniqid(), lastname: $lastname), 'Secret-Pass-123!', []);
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
		$_SERVER['REQUEST_URI'] = '/user/'.$id.'/note';

		return Request::fromGlobals('')->withInputFilter(new LegacyInputFilter());
	}

	private function legacyMe(): CurrentUser
	{
		$legacyUser = $this->ctx->user;
		self::assertNotNull($legacyUser);

		return new LegacyCurrentUser($legacyUser);
	}

	private function dispatcher(): EventDispatcher
	{
		$dispatcher = new EventDispatcher();
		(new NoteTriggerBridge(new LegacyTriggers($this->ctx), $this->ctx, new LegacyObjectLoader($this->connection), new Logger(), $this->registry()))->register($dispatcher);

		return $dispatcher;
	}

	private function controller(?CurrentUser $me = null, ?ScriptedHooks $scripted = null, ?EventDispatcher $dispatcher = null, ?Connection $connection = null): NotesController
	{
		$ctx = $this->ctx;
		$me ??= $this->legacyMe();
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class, UserListController::class, NotesController::class]), '');
		$hm = $ctx->hookmanager;
		if ($scripted !== null) {
			$hm = new \HookManager($ctx->db);
			ScriptedHooks::register($hm, 'usernote', $scripted);
		}
		$container = new Container();
		$container->instance(UserRepository::class, $this->repo());
		$container->instance(UserPermissionChecker::class, new UserPermissionChecker($me, false, false, false, [], false, false, (int) $ctx->conf->entity));
		$container->instance(UserCardChrome::class, new UserCardChrome($ctx, $urls));

		return new NotesController(
			$this->registry(),
			new EntityServices($container),
			$me,
			new LegacyHooks($hm),
			new LegacyObjectLoader($this->connection),
			new NotesRepository($this->connection),
			new NotesChrome($ctx),
			$dispatcher ?? $this->dispatcher(),
			$connection ?? $this->connection,
			new FlashBag(),
			new LegacyTranslator($ctx->langs),
			$urls
		);
	}

	private function registry(): EntityRegistry
	{
		$registry = new EntityRegistry();
		foreach ((new UserModule())->entities() as $definition) {
			$registry->register($definition);
		}

		return $registry;
	}

	private function repo(): UserRepository
	{
		return new UserRepository($this->connection, (int) $this->ctx->conf->entity);
	}
}
