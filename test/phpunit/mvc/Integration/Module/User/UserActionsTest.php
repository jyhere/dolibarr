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
use Dolibarr\Core\View\Confirm;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\Service\UserValidator;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Module\User\View\UserCardHtml;
use Dolibarr\Module\User\View\UserFormChrome;
use Dolibarr\Tests\Integration\Fixture\LimitUsersConfig;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use Dolibarr\Tests\Integration\Fixture\ScriptedHooks;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

/**
 * Status, delete, password and groups actions of the card, and the dialogs and blocks of the card.
 * Every test runs in a transaction rolled back in tearDown; the disposable users and groups are created inside it.
 */
final class UserActionsTest extends TestCase
{
	use OverridesLegacyConf;

	private const PASSWORD = 'Secret-Pass-123!';

	private LegacyContext $ctx;

	private Connection $connection;

	private ?\stdClass $browser = null;

	/** @var array<mixed> */
	private array $session = [];

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
	}

	public function testDisableSelfIsRefused(): void
	{
		$actor = $this->disposable('Actor');

		// card.php l.216: a user can't disable himself, nothing is said
		$response = $this->controller($this->nonAdmin($actor, ['user.user.read', 'user.user.delete']))
			->setStatus($this->post($actor->id, ['action' => 'confirm_disable', 'confirm' => 'yes']), $actor->id);

		$this->assertRedirectsToCard($response, $actor->id);
		self::assertSame(1, $this->repo()->find($actor->id)?->status);
		self::assertArrayNotHasKey('errors', $_SESSION['dol_events'] ?? []);
	}

	public function testNonAdminCannotDisableAdmin(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Admin', true);

		$response = $this->controller($this->nonAdmin($actor, ['user.user.read', 'user.user.delete']))
			->setStatus($this->post($target->id, ['action' => 'confirm_disable', 'confirm' => 'yes']), $target->id);

		$this->assertRedirectsToCard($response, $target->id);
		self::assertSame(1, $this->repo()->find($target->id)?->status);
		// card.php l.218-221
		self::assertSame([$this->trans('OnlyAdminUsersCanDisableAdminUsers')], $_SESSION['dol_events']['errors'] ?? []);
	}

	public function testAdminDisablesThenEnables(): void
	{
		$target = $this->disposable('Target');

		$response = $this->controller()->setStatus($this->post($target->id, ['action' => 'confirm_disable', 'confirm' => 'yes']), $target->id);
		$this->assertRedirectsToCard($response, $target->id);
		self::assertSame(0, $this->repo()->find($target->id)?->status);

		$response = $this->controller()->setStatus($this->post($target->id, ['action' => 'confirm_enable', 'confirm' => 'yes']), $target->id);
		$this->assertRedirectsToCard($response, $target->id);
		self::assertSame(1, $this->repo()->find($target->id)?->status);
		// card.php l.226 and l.248: no message
		self::assertSame([], $_SESSION['dol_events'] ?? []);
	}

	public function testConfirmNoWritesNothing(): void
	{
		$target = $this->disposable('Target');

		$response = $this->controller()->setStatus($this->post($target->id, ['action' => 'confirm_disable', 'confirm' => 'no']), $target->id);

		$this->assertRedirectsToCard($response, $target->id);
		self::assertSame(1, $this->repo()->find($target->id)?->status);
	}

	public function testEnableRefusedWhenQuotaReached(): void
	{
		$target = $this->disposable('Target');
		$this->connection->execute('UPDATE '.MAIN_DB_PREFIX.'user SET statut = 0 WHERE rowid = '.$target->id);

		// card.php l.235-241: getNbOfUsers('active') >= $dolibarr_main_limit_users
		$config = new LimitUsersConfig(new LegacyConfig($this->ctx), $this->repo()->countForQuota());
		$response = $this->controller(null, null, $config)->setStatus($this->post($target->id, ['action' => 'confirm_enable', 'confirm' => 'yes']), $target->id);

		$this->assertRedirectsToCard($response, $target->id);
		self::assertSame(0, $this->repo()->find($target->id)?->status);
		self::assertSame([$this->trans('YourQuotaOfUsersIsReached')], $_SESSION['dol_events']['errors'] ?? []);
	}

	public function testInvalidTokenOnStatusWritesNothing(): void
	{
		$target = $this->disposable('Target');
		$hooks = new ScriptedHooks([]);

		// main.inc.php on an invalid token: $_POST emptied, action and confirm removed from $_GET, errorcode set
		$response = $this->controller(null, $hooks)->setStatus($this->request('POST', ['errorcode' => 'InvalidToken'], [], $target->id), $target->id);

		$this->assertRedirectsToCard($response, $target->id);
		self::assertSame('', $hooks->actions['doActions']);
		self::assertSame(1, $this->repo()->find($target->id)?->status);
	}

	public function testDoActionsReceivesTheActionAndCanVetoTheDelete(): void
	{
		$target = $this->disposable('Target');
		$hooks = new ScriptedHooks(['doActions' => ['code' => -1, 'errors' => ['Hook says no']]]);

		$response = $this->controller(null, $hooks)->delete($this->post($target->id, ['action' => 'confirm_delete', 'confirm' => 'yes']), $target->id);

		$this->assertRedirectsToCard($response, $target->id);
		self::assertSame('confirm_delete', $hooks->actions['doActions']);
		self::assertSame($target->id, $hooks->parameters['doActions']['id'] ?? null);
		self::assertNotNull($this->repo()->find($target->id));
		self::assertSame(['Hook says no'], $_SESSION['dol_events']['errors'] ?? []);
	}

	public function testDeleteRedirectsToListAndRemovesRow(): void
	{
		$target = $this->disposable('Doomed');

		$response = $this->controller()->delete($this->post($target->id, ['action' => 'confirm_delete', 'confirm' => 'yes']), $target->id);

		self::assertInstanceOf(RedirectResponse::class, $response);
		// card.php l.277-279
		self::assertSame(DOL_URL_ROOT.'/user/list.php?restore_lastsearch_values=1', $response->headers()['Location']);
		self::assertNull($this->repo()->find($target->id));
		self::assertSame([$this->trans('RecordDeleted')], $_SESSION['dol_events']['mesgs'] ?? []);
	}

	public function testNonAdminCannotDeleteAdmin(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Admin', true);

		$response = $this->controller($this->nonAdmin($actor, ['user.user.read', 'user.user.delete']))
			->delete($this->post($target->id, ['action' => 'confirm_delete', 'confirm' => 'yes']), $target->id);

		$this->assertRedirectsToCard($response, $target->id);
		self::assertNotNull($this->repo()->find($target->id));
		// card.php l.261-263
		self::assertSame([$this->trans('OnlyAdminUsersCanDeleteAdminUsers')], $_SESSION['dol_events']['errors'] ?? []);
	}

	public function testResetPasswordReturnsNewClearAndFlashesIt(): void
	{
		$target = $this->disposable('Target');
		$before = $this->passCrypted($target->id);

		$response = $this->controller()->setPassword($this->post($target->id, ['action' => 'confirm_password', 'confirm' => 'yes']), $target->id);

		$this->assertRedirectsToCard($response, $target->id);
		$after = $this->passCrypted($target->id);
		self::assertNotSame($before, $after);
		// card.php l.793: the new clear password shown as a warning
		$warnings = $_SESSION['dol_events']['warnings'] ?? [];
		self::assertIsArray($warnings);
		self::assertCount(1, $warnings);
		[$prefix, $suffix] = explode('@@', $this->trans('PasswordChangedTo', '@@'));
		self::assertStringStartsWith($prefix, (string) $warnings[0]);
		$clear = html_entity_decode(substr((string) $warnings[0], strlen($prefix), strlen((string) $warnings[0]) - strlen($prefix) - strlen($suffix)), ENT_COMPAT, 'UTF-8');
		self::assertNotSame('', $clear);
		self::assertTrue(dol_verifyHash($clear, $after), 'The flashed password is the stored one');
	}

	public function testResetPasswordWithoutRightWritesNothing(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');
		$before = $this->passCrypted($target->id);

		// card.php l.156: setting another user's password also needs to be an admin
		$this->controller($this->nonAdmin($actor, ['user.user.read', 'user.user.password']))
			->setPassword($this->post($target->id, ['action' => 'confirm_password', 'confirm' => 'yes']), $target->id);

		self::assertSame($before, $this->passCrypted($target->id));
	}

	public function testAddAndRemoveGroup(): void
	{
		$target = $this->disposable('Member');
		$groupId = $this->group();

		$response = $this->controller()->groups($this->post($target->id, ['action' => 'addgroup', 'group' => (string) $groupId]), $target->id);
		$this->assertRedirectsToCard($response, $target->id);
		self::assertSame(1, $this->membership($target->id, $groupId));

		$response = $this->controller()->groups($this->post($target->id, ['action' => 'removegroup', 'group' => (string) $groupId]), $target->id);
		$this->assertRedirectsToCard($response, $target->id);
		self::assertSame(0, $this->membership($target->id, $groupId));
	}

	public function testGroupsWithoutRightWritesNothing(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Member');
		$groupId = $this->group();

		$this->controller($this->nonAdmin($actor, ['user.user.read']))->groups($this->post($target->id, ['action' => 'addgroup', 'group' => (string) $groupId]), $target->id);

		self::assertSame(0, $this->membership($target->id, $groupId));
	}

	public function testShowRendersTheDisableDialogPostingToTheStatusRoute(): void
	{
		$target = $this->disposable('Target');

		ob_start();
		try {
			$vm = $this->controller()->show($this->request('GET', ['confirm' => 'disable'], [], $target->id), $target->id);
		} finally {
			$printed = ob_get_clean();
		}

		self::assertSame('', $printed);
		$dialog = $vm->data['confirmDialogHtml'];
		self::assertIsString($dialog);
		self::assertStringContainsString('<form method="POST" action="/user/'.$target->id.'/status"', $dialog);
		self::assertStringContainsString('name="action" value="confirm_disable"', $dialog);
		self::assertStringContainsString('name="token"', $dialog);
		self::assertStringContainsString('name="confirm"', $dialog);
		self::assertStringContainsString($this->trans('DisableAUser'), $dialog);
	}

	public function testShowWithoutConfirmHasNoDialog(): void
	{
		$target = $this->disposable('Target');

		$vm = $this->controller()->show($this->request('GET', [], [], $target->id), $target->id);

		self::assertSame('', $vm->data['confirmDialogHtml']);
	}

	public function testPasswordFormRendersTheCardWithTheDialog(): void
	{
		$target = $this->disposable('Target');

		$vm = $this->controller()->passwordForm($this->request('GET', ['mode' => 'send'], [], $target->id), $target->id);

		self::assertSame('user:user_show', $vm->template);
		$dialog = $vm->data['confirmDialogHtml'];
		self::assertIsString($dialog);
		self::assertStringContainsString('action="/user/'.$target->id.'/password"', $dialog);
		self::assertStringContainsString('value="confirm_passwordsend"', $dialog);

		$vm = $this->controller()->passwordForm($this->request('GET', [], [], $target->id), $target->id);
		self::assertStringContainsString('value="confirm_password"', (string) $vm->data['confirmDialogHtml']);
	}

	public function testCardButtonsPointToTheNewRoutes(): void
	{
		$target = $this->disposable('Target');

		$vm = $this->controller()->show($this->request('GET', [], [], $target->id), $target->id);

		$card = $vm->data['card'];
		self::assertInstanceOf(UserCardHtml::class, $card);
		$id = $target->id;
		self::assertStringContainsString('href="/user/'.$id.'/password"', $card->actionsHtml);
		self::assertStringContainsString('href="/user/'.$id.'?confirm=disable"', $card->actionsHtml);
		self::assertStringContainsString('href="/user/'.$id.'?confirm=delete"', $card->actionsHtml);
		self::assertStringNotContainsString('action=password', $card->actionsHtml);
		self::assertStringNotContainsString('action=disable', $card->actionsHtml);
		self::assertStringNotContainsString('action=delete', $card->actionsHtml);
	}

	public function testCardShowsTheGroupsBlockWithPostOnlyRemovals(): void
	{
		$target = $this->disposable('Member');
		$groupId = $this->group();
		$this->connection->execute('INSERT INTO '.MAIN_DB_PREFIX.'usergroup_user (entity, fk_user, fk_usergroup) VALUES (1, '.$target->id.', '.$groupId.')');

		$vm = $this->controller()->show($this->request('GET', [], [], $target->id), $target->id);

		$html = $vm->data['groupsHtml'];
		self::assertIsString($html);
		self::assertStringContainsString('<form action="/user/'.$target->id.'/groups" method="POST">', $html);
		self::assertStringContainsString('name="action" value="addgroup"', $html);
		self::assertStringContainsString('name="group"', $html);
		self::assertStringContainsString('name="action" value="removegroup"', $html);
		self::assertStringContainsString('name="group" value="'.$groupId.'"', $html);
		self::assertStringNotContainsString('action=removegroup', $html, 'No removal through a GET link');
		self::assertStringContainsString('mvc_test_group_', $html);

		$bottom = $vm->data['bottomHtml'];
		self::assertIsString($bottom);
		self::assertStringContainsString('fichehalfleft', $bottom);
		self::assertStringContainsString('fichehalfright', $bottom);
		self::assertStringContainsString('/user/agenda.php?id='.$target->id, $bottom);
	}

	public function testGroupsBlockHiddenWithoutReadRight(): void
	{
		$actor = $this->disposable('Actor');

		// Oneself without user->user->read: the card is readable (card.php l.147), the groups are not (l.136)
		$vm = $this->controller($this->nonAdmin($actor, []))->show($this->request('GET', [], [], $actor->id), $actor->id);

		self::assertSame('', $vm->data['groupsHtml']);
	}

	/**
	 * A message as the controller translates it (plain text, see LegacyTranslator).
	 */
	private function trans(string $key, string ...$args): string
	{
		return (new LegacyTranslator($this->ctx->langs))->trans($key, ...$args);
	}

	private function assertRedirectsToCard(mixed $response, int $id): void
	{
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/'.$id, $response->headers()['Location']);
	}

	private function disposable(string $lastname, bool $admin = false): User
	{
		$service = new UserService($this->repo(), $this->writer(), new EventDispatcher(), $this->connection, $this->legacyMe());

		return $service->create(new User(0, 1, 'mvc_test_'.uniqid(), admin: $admin, lastname: $lastname), self::PASSWORD, []);
	}

	private function group(): int
	{
		require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
		$group = new \UserGroup($this->ctx->db);
		$group->name = 'mvc_test_group_'.uniqid();
		$group->entity = 1;
		$id = $group->create();
		self::assertGreaterThan(0, $id);

		return (int) $id;
	}

	private function membership(int $userId, int $groupId): int
	{
		$row = $this->connection->fetchOne('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'usergroup_user WHERE fk_user = '.$userId.' AND fk_usergroup = '.$groupId);

		return (int) ($row?->nb ?? 0);
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
		$_SERVER['REQUEST_URI'] = '/user/'.$id;

		return Request::fromGlobals('')->withInputFilter(new LegacyInputFilter());
	}

	private function legacyMe(): CurrentUser
	{
		$legacyUser = $this->ctx->user;
		self::assertNotNull($legacyUser);

		return new LegacyCurrentUser($legacyUser);
	}

	private function controller(?CurrentUser $me = null, ?ScriptedHooks $scripted = null, ?Config $config = null): UserController
	{
		$ctx = $this->ctx;
		$me ??= $this->legacyMe();
		$config ??= new LegacyConfig($ctx);
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
			$urls,
			new Confirm($ctx)
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
