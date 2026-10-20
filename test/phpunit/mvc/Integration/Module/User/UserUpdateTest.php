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
use Dolibarr\Tests\Integration\Fixture\ScriptedHooks;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

/**
 * The edit form and the update action. Every test runs in a transaction rolled back in tearDown; the
 * disposable users are created inside it.
 */
final class UserUpdateTest extends TestCase
{
	use OverridesLegacyConf;

	private const PASSWORD = 'Secret-Pass-123!';

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
		$conf = $this->ctx->conf;
		$this->browser = $conf->browser;
		$conf->browser = clone $conf->browser;
		if (!isset($conf->browser->layout)) {
			$conf->browser->layout = 'classic';
		}
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

	public function testEditFormShowsTheUser(): void
	{
		$target = $this->disposable('Shown');

		ob_start();
		try {
			$vm = $this->controller()->editForm($this->request('GET', [], [], $target->id), $target->id);
		} finally {
			$printed = ob_get_clean();
		}
		self::assertSame('', $printed);

		self::assertSame('user:user_form', $vm->template);
		self::assertSame('edit', $vm->data['mode']);
		self::assertSame('/user/'.$target->id.'/edit', $vm->data['formAction']);
		$values = $vm->data['values'];
		self::assertInstanceOf(User::class, $values);
		self::assertSame('Shown', $values->lastname);
		self::assertSame($target->entity, $vm->data['entity']);
		self::assertTrue($vm->data['canEdit']);
		$f = $vm->data['f'];
		self::assertIsArray($f);
		// An admin on another user: third party and contact selectors (card.php l.2575-2608)
		self::assertStringContainsString('name="socid"', $f['company']);
		self::assertStringContainsString('contactid', $f['company']);
		self::assertStringContainsString('<select', $f['admin']);
		self::assertStringContainsString('type="password"', $f['passwordField']);
		self::assertStringContainsString('value=""', $f['passwordField']);
		self::assertStringContainsString('/user/'.$target->id, $f['ficheHead']);
	}

	public function testEditFormHidesAdminFlagForNonAdmin(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');

		$vm = $this->controller($this->nonAdmin($actor, ['user.user.read', 'user.user.write']))->editForm($this->request('GET', [], [], $target->id), $target->id);

		$f = $vm->data['f'];
		self::assertIsArray($f);
		self::assertStringNotContainsString('<select', $f['admin']);
		self::assertStringContainsString('<input type="hidden" name="admin" value="0">', $f['admin']);
		self::assertArrayNotHasKey('company', $f, 'Only an admin links a third party or a contact (card.php l.2557)');
		self::assertFalse($vm->data['canEditLogin']);
	}

	public function testEditFormIsForbiddenWithoutRight(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');

		// card.php l.152-154: the write right without the read one is refused too
		try {
			$this->controller($this->nonAdmin($actor, ['user.user.write']))->editForm($this->request('GET', [], [], $target->id), $target->id);
			self::fail('AccessDeniedException expected');
		} catch (AccessDeniedException) {
		}

		$this->expectException(AccessDeniedException::class);
		$this->controller($this->nonAdmin($actor, ['user.user.read']))->editForm($this->request('GET', [], [], $target->id), $target->id);
	}

	public function testEditFormOfAnUnknownUserIsNotFound(): void
	{
		$this->expectException(NotFoundException::class);
		$this->controller()->editForm($this->request('GET', [], [], 999999), 999999);
	}

	public function testUpdateChangesLastnameAndKeepsLoginForNonAdmin(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');

		$response = $this->controller($this->nonAdmin($actor, ['user.user.read', 'user.user.write']))->update(
			$this->request('POST', [], $this->posted($target, ['lastname' => 'Changed', 'login' => 'mvc_test_hijack']), $target->id),
			$target->id
		);

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/'.$target->id, $response->headers()['Location']);
		$after = $this->repo()->find($target->id);
		self::assertSame('Changed', $after?->lastname);
		self::assertSame($target->login, $after?->login);
		$this->ctx->langs->load('users');
		self::assertSame([$this->ctx->langs->transnoentities('UserModified')], $_SESSION['dol_events']['mesgs'] ?? []);
		self::assertArrayNotHasKey('errors', $_SESSION['dol_events'] ?? []);
	}

	public function testUpdateLinksContactAndCompany(): void
	{
		$target = $this->disposable('Linked');
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX."societe (nom, entity, datec) VALUES ('MVC test company', 1, NOW())");
		$companyId = $this->connection->lastInsertId(MAIN_DB_PREFIX.'societe');
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX."socpeople (lastname, fk_soc, entity, datec, fk_user_creat) VALUES ('MVC contact', ".$companyId.', 1, NOW(), 1)');
		$contactId = $this->connection->lastInsertId(MAIN_DB_PREFIX.'socpeople');

		$response = $this->controller()->update(
			$this->request('POST', [], $this->posted($target, ['contactid' => (string) $contactId, 'socid' => '-1']), $target->id),
			$target->id
		);

		self::assertInstanceOf(RedirectResponse::class, $response);
		$after = $this->repo()->find($target->id);
		self::assertSame($contactId, $after?->contactId);
		self::assertSame($companyId, $after?->companyId, 'The contact gives its company (card.php l.660-664)');
		$contact = $this->connection->fetchOne('SELECT lastname FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId);
		self::assertSame('MVC contact', $contact?->lastname, 'The newly linked contact is not overwritten by the user');
	}

	public function testUpdateByNonAdminOnAdminIsForbidden(): void
	{
		$actor = $this->disposable('Actor');
		$admin = $this->disposable('Admin', true);
		self::assertTrue($admin->admin);

		// Without the write right (nor the password one): 403, nothing written
		try {
			$this->controller($this->nonAdmin($actor, ['user.user.read']))->update(
				$this->request('POST', [], $this->posted($admin, ['lastname' => 'Hacked']), $admin->id),
				$admin->id
			);
			self::fail('AccessDeniedException expected');
		} catch (AccessDeniedException) {
		}
		self::assertSame('Admin', $this->repo()->find($admin->id)?->lastname);

		// With user.user.write, card.php lets the other fields be edited, but never the admin flag nor the login (l.520-526)
		$this->controller($this->nonAdmin($actor, ['user.user.read', 'user.user.write']))->update(
			$this->request('POST', [], $this->posted($admin, ['admin' => '0', 'login' => 'mvc_test_downgraded']), $admin->id),
			$admin->id
		);
		$after = $this->repo()->find($admin->id);
		self::assertTrue($after?->admin);
		self::assertSame($admin->login, $after?->login);
	}

	public function testPasswordOnlyActorCanOnlyChangePassword(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');
		// card.php l.156 and l.757-772: an admin with user.user.password but not user.user.write
		$me = new RightsCurrentUser($actor->id, true, ['user.user.read', 'user.user.password']);

		$vm = $this->controller($me)->editForm($this->request('GET', [], [], $target->id), $target->id);
		self::assertFalse($vm->data['canEdit']);
		$f = $vm->data['f'];
		self::assertIsArray($f);
		self::assertStringContainsString('type="password"', $f['passwordField']);

		$response = $this->controller($me)->update(
			$this->request('POST', [], $this->posted($target, ['lastname' => 'Hacked', 'password' => 'Changed-Pass-456!']), $target->id),
			$target->id
		);

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/'.$target->id, $response->headers()['Location']);
		self::assertSame('Target', $this->repo()->find($target->id)?->lastname);
		self::assertTrue(dol_verifyHash('Changed-Pass-456!', $this->passCrypted($target->id)));
		self::assertArrayNotHasKey('mesgs', $_SESSION['dol_events'] ?? [], 'card.php shows no message there');
	}

	public function testPostedPasswordIsSetWithTheUpdate(): void
	{
		$target = $this->disposable('Target');

		$this->controller()->update($this->request('POST', [], $this->posted($target, ['password' => 'Changed-Pass-456!', 'job' => 'Pilot']), $target->id), $target->id);

		self::assertTrue(dol_verifyHash('Changed-Pass-456!', $this->passCrypted($target->id)));
		self::assertSame('Pilot', $this->repo()->find($target->id)?->job);
	}

	public function testRefusedPasswordRollsTheUpdateBack(): void
	{
		$target = $this->disposable('Target');
		$this->setConf('USER_PASSWORD_GENERATED', 'perso');
		$this->setConf('USER_PASSWORD_PATTERN', '12;1;1;1;1;1');

		$response = $this->controller()->update($this->request('POST', [], $this->posted($target, ['password' => 'short', 'job' => 'Pilot']), $target->id), $target->id);

		// The password is set in the update transaction, which is rolled back (card.php: update() fails as a whole
		// when setPassword() fails). Inside the outer test transaction the DoliDB nested rollback only decrements
		// the counter, so the rolled back job cannot be observed here: the closed transaction is.
		self::assertInstanceOf(ViewModel::class, $response);
		self::assertSame('edit', $response->data['mode']);
		self::assertNotEmpty($_SESSION['dol_events']['errors'] ?? []);
		self::assertArrayNotHasKey('mesgs', $_SESSION['dol_events'] ?? []);
		self::assertTrue(dol_verifyHash(self::PASSWORD, $this->passCrypted($target->id)));
		self::assertSame(1, (int) $this->ctx->db->transaction_opened);
	}

	public function testForcePasswordChangeIsStored(): void
	{
		$target = $this->disposable('Target');

		$this->controller()->update($this->request('POST', [], $this->posted($target, ['forcepasswordchange' => '1']), $target->id), $target->id);

		self::assertTrue($this->repo()->find($target->id)?->forcePasswordChange);
	}

	public function testInvalidTokenWritesNothing(): void
	{
		$target = $this->disposable('Target');
		$hooks = new ScriptedHooks([]);

		// main.inc.php on an invalid token: $_POST emptied, errorcode set in $_GET
		$response = $this->controller(null, $hooks)->update($this->request('POST', ['errorcode' => 'InvalidToken', 'action' => 'update'], [], $target->id), $target->id);

		self::assertInstanceOf(ViewModel::class, $response);
		self::assertSame('user:user_form', $response->template);
		self::assertSame('', $hooks->actions['doActions']);
		self::assertSame('Target', $this->repo()->find($target->id)?->lastname);
		self::assertArrayNotHasKey('errors', $_SESSION['dol_events'] ?? []);
	}

	public function testDoActionsReceivesUpdateAndCanVeto(): void
	{
		$target = $this->disposable('Target');
		$hooks = new ScriptedHooks(['doActions' => ['code' => -1, 'errors' => ['Hook says no']]]);

		$response = $this->controller(null, $hooks)->update($this->request('POST', [], $this->posted($target, ['lastname' => 'Vetoed']), $target->id), $target->id);

		self::assertSame('update', $hooks->actions['doActions']);
		self::assertSame($target->id, $hooks->parameters['doActions']['id'] ?? null);
		self::assertInstanceOf(ViewModel::class, $response);
		$values = $response->data['values'];
		self::assertInstanceOf(User::class, $values);
		self::assertSame('Vetoed', $values->lastname);
		self::assertSame('Target', $this->repo()->find($target->id)?->lastname);
		self::assertSame(['Hook says no'], $_SESSION['dol_events']['errors'] ?? []);
	}

	public function testCancelRedirectsToTheCardWithoutWriting(): void
	{
		$target = $this->disposable('Target');

		$response = $this->controller()->update($this->request('POST', [], $this->posted($target, ['lastname' => 'Cancelled', 'cancel' => 'Cancel']), $target->id), $target->id);

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/'.$target->id, $response->headers()['Location']);
		self::assertSame('Target', $this->repo()->find($target->id)?->lastname);
	}

	public function testMissingLastnameRedisplaysTheForm(): void
	{
		$target = $this->disposable('Target');

		$response = $this->controller()->update($this->request('POST', [], $this->posted($target, ['lastname' => '']), $target->id), $target->id);

		self::assertInstanceOf(ViewModel::class, $response);
		self::assertSame('edit', $response->data['mode']);
		$this->ctx->langs->load('errors');
		self::assertSame([$this->ctx->langs->transnoentities('NameNotDefined')], $_SESSION['dol_events']['errors'] ?? []);
		self::assertSame('Target', $this->repo()->find($target->id)?->lastname);
	}

	public function testDuplicateLoginOnUpdateFlashesTheLegacyMessage(): void
	{
		$other = $this->disposable('Other');
		$target = $this->disposable('Target');

		ob_start();
		try {
			$response = $this->controller()->update($this->request('POST', [], $this->posted($target, ['login' => $other->login, 'job' => 'Dup']), $target->id), $target->id);
		} finally {
			$printed = ob_get_clean();
		}
		self::assertSame('', $printed);

		self::assertInstanceOf(ViewModel::class, $response);
		self::assertSame('user:user_form', $response->template);
		$values = $response->data['values'];
		self::assertInstanceOf(User::class, $values);
		self::assertSame($other->login, $values->login);
		// \User::update() refuses it before the SQL: card.php shows $object->error (l.674-680)
		$this->ctx->langs->load('errors');
		self::assertSame([$this->ctx->langs->trans('ErrorLoginAlreadyExists', $other->login)], $_SESSION['dol_events']['errors'] ?? []);
		$after = $this->repo()->find($target->id);
		self::assertSame($target->login, $after?->login);
		self::assertSame('', $after?->job ?? '');
		self::assertSame(1, (int) $this->ctx->db->transaction_opened, 'The service transaction is closed');
	}

	public function testChangingOwnLoginWarns(): void
	{
		$self = $this->disposable('Self');
		// RightsCurrentUser::login() is "user<id>": the stored login is made to match
		$this->connection->execute('UPDATE '.MAIN_DB_PREFIX."user SET login = 'user".$self->id."' WHERE rowid = ".$self->id);
		$self = $this->repo()->find($self->id);
		self::assertNotNull($self);
		$me = new RightsCurrentUser($self->id, true, ['user.self.write']);

		$this->controller($me)->update($this->request('POST', [], $this->posted($self, ['login' => 'user'.$self->id.'x']), $self->id), $self->id);

		$this->ctx->langs->load('errors');
		self::assertSame([$this->ctx->langs->transnoentities('WarningYourLoginWasModifiedPleaseLogin')], $_SESSION['dol_events']['warnings'] ?? []);
		self::assertSame('user'.$self->id.'x', $this->repo()->find($self->id)?->login);
	}

	/**
	 * What the edit form posts for $u (the fields of card.php l.2498-3309), with $changes on top.
	 *
	 * @param array<string, string> $changes
	 * @return array<string, string>
	 */
	private function posted(User $u, array $changes): array
	{
		return $changes + [
			'action' => 'update',
			'entity' => (string) $u->entity,
			'lastname' => (string) $u->lastname,
			'firstname' => (string) $u->firstname,
			'login' => $u->login,
			'admin' => $u->admin ? '1' : '0',
			'employee' => $u->employee ? '1' : '0',
			'email' => (string) $u->email,
			'job' => (string) $u->job,
		];
	}

	private function disposable(string $lastname, bool $admin = false): User
	{
		$login = 'mvc_test_'.uniqid();
		$service = new UserService($this->repo(), $this->writer(), new EventDispatcher(), $this->connection, $this->legacyMe());
		$created = $service->create(new User(0, 1, $login, admin: $admin, lastname: $lastname), self::PASSWORD, []);

		return $created;
	}

	/**
	 * @param list<string> $rights
	 */
	private function nonAdmin(User $actor, array $rights): CurrentUser
	{
		return new RightsCurrentUser($actor->id, false, $rights);
	}

	private function passCrypted(int $id): string
	{
		$row = $this->connection->fetchOne('SELECT pass_crypted FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$id);

		return (string) ($row?->pass_crypted ?? '');
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
		$_SERVER['REQUEST_URI'] = '/user/'.$id.'/edit';

		return Request::fromGlobals('')->withInputFilter(new LegacyInputFilter());
	}

	private function legacyMe(): CurrentUser
	{
		$legacyUser = $this->ctx->user;
		self::assertNotNull($legacyUser);

		return new LegacyCurrentUser($legacyUser);
	}

	private function controller(?CurrentUser $me = null, ?ScriptedHooks $scripted = null): UserController
	{
		$ctx = $this->ctx;
		$me ??= $this->legacyMe();
		$config = new LegacyConfig($ctx);
		$perms = new UserPermissionChecker($me, false, false, false, [], false, false, (int) $ctx->conf->entity);
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class]), '');
		$loader = new LegacyObjectLoader($this->connection);
		$translator = new LegacyTranslator($ctx->langs);
		$hm = $ctx->hookmanager;
		if ($scripted !== null) {
			$hm = new \HookManager($ctx->db);
			ScriptedHooks::register($hm, 'usercard', $scripted);
		}

		return new UserController(
			$this->repo(),
			$perms,
			$me,
			new LegacyHooks($hm),
			$loader,
			$translator,
			new UserCardChrome($ctx, $urls),
			new FlashBag(),
			new UserService($this->repo(), new LegacyUserWriter($ctx, $loader, $this->connection), new EventDispatcher(), $this->connection, $me),
			new UserValidator($translator, $config),
			new UserFormChrome($ctx, $urls),
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
