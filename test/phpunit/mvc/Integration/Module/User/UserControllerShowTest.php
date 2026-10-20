<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyCurrentUser;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTranslator;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\Confirm;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Entity\UserView;
use Dolibarr\Module\User\Entity\Visibility;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\Service\UserValidator;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Module\User\View\UserFormChrome;
use Dolibarr\Module\User\View\UserCardHtml;
use Dolibarr\Tests\Integration\Fixture\GlobalsRequest;
use Dolibarr\Tests\Integration\Fixture\ScriptedHooks;
use PHPUnit\Framework\TestCase;

final class UserControllerShowTest extends TestCase
{
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
		// Browser detection is done by main.inc.php, not by master.inc.php used here: the banner reads it.
		$this->session = $_SESSION ?? [];
		$conf = $GLOBALS['conf'];
		$this->browser = $conf->browser;
		$conf->browser = clone $conf->browser;
		if (!isset($conf->browser->layout)) {
			$conf->browser->layout = 'classic';
		}
	}

	protected function tearDown(): void
	{
		$GLOBALS['conf']->browser = $this->browser;
		$_SESSION = $this->session;
		$_GET = $this->get;
		$_POST = $this->post;
	}

	public function testShowBuildsViewModelWithEntityAndTabs(): void
	{
		$vm = $this->controller()->show(GlobalsRequest::create('GET', '/user/1', [], []), 1);

		self::assertSame('user:user_show', $vm->template);
		$user = $vm->exposed['user'];
		self::assertSame(['user'], array_keys($vm->exposed));
		self::assertArrayNotHasKey('user', $vm->data);
		self::assertInstanceOf(UserView::class, $user);
		self::assertSame(1, $user->toArray()['id']);
		self::assertSame(Visibility::Admin, $user->visibility);
		$card = $vm->data['card'];
		self::assertInstanceOf(UserCardHtml::class, $card);
		self::assertNotEmpty($card->tabsHtml);
		self::assertStringContainsString('href="/user/1"', $card->tabsHtml);
		self::assertStringContainsString('arearef', $card->bannerHtml);
		self::assertSame('', $vm->data['hookFormObjectOptions']);
	}

	public function testAdminViewingSelfGetsModifyAndCannotDisableHimself(): void
	{
		$vm = $this->controller()->show(GlobalsRequest::create('GET', '/user/1', [], []), 1);
		$card = $vm->data['card'];
		self::assertInstanceOf(UserCardHtml::class, $card);
		$langs = $GLOBALS['langs'];
		self::assertStringContainsString('href="/user/1/edit"', $card->actionsHtml);
		self::assertStringContainsString('>'.$langs->trans('Modify').'<', $card->actionsHtml);
		self::assertMatchesRegularExpression(
			'/<span[^>]*butActionRefused[^>]*title="'.preg_quote(dol_escape_htmltag($langs->trans('CantDisableYourself')), '/').'"[^>]*><span class="textbutton">'.preg_quote($langs->trans('DisableUser'), '/').'</',
			$card->actionsHtml
		);
		self::assertStringNotContainsString('action=disable', $card->actionsHtml);
		self::assertStringNotContainsString('action=delete', $card->actionsHtml);
	}

	public function testShowUnknownThrowsNotFound(): void
	{
		$this->expectException(NotFoundException::class);
		$this->controller()->show(GlobalsRequest::create('GET', '/user/999999', [], []), 999999);
	}

	public function testHookOutputIsComposedAsCardPhpPrintsIt(): void
	{
		$hooks = new ScriptedHooks([
			'doActions' => ['echo' => '[echo doActions]', 'print' => '[print doActions]'],
			'formObjectOptions' => ['echo' => '[echo options]', 'print' => '[print options]'],
			'addMoreObjectBlock' => ['echo' => '[echo block]', 'print' => '[print block]'],
			'addMoreActionsButtons' => ['echo' => '[echo buttons]', 'print' => '[print buttons]'],
		]);

		ob_start();
		$vm = $this->controller($hooks)->show(GlobalsRequest::create('GET', '/user/1', [], []), 1);
		self::assertSame('', ob_get_clean());

		self::assertSame(['doActions', 'formObjectOptions', 'addMoreObjectBlock', 'addMoreActionsButtons'], $hooks->calls);
		self::assertSame('[echo doActions]', $vm->data['outputBeforeHeader']);
		self::assertSame('[echo options][print options]', $vm->data['hookFormObjectOptions']);
		self::assertSame('[echo block]', $vm->data['hookMoreObjectBlock']);
		self::assertSame('[echo buttons]', $vm->data['hookMoreActionsButtons']);
		self::assertTrue($vm->data['showActions']);
	}

	public function testReplacingHooksPrintTheirBlockAndHideTheStandardButtons(): void
	{
		$hooks = new ScriptedHooks([
			'addMoreObjectBlock' => ['echo' => '[echo block]', 'print' => '[print block]', 'code' => 1],
			'addMoreActionsButtons' => ['echo' => '[echo buttons]', 'print' => '[print buttons]', 'code' => 1],
		]);

		$vm = $this->controller($hooks)->show(GlobalsRequest::create('GET', '/user/1', [], []), 1);

		self::assertSame('[echo block][print block]', $vm->data['hookMoreObjectBlock']);
		self::assertSame('[echo buttons]', $vm->data['hookMoreActionsButtons']);
		self::assertFalse($vm->data['showActions']);
	}

	public function testDoActionsReceivesTheCardParametersAndItsErrorsAreFlashed(): void
	{
		$_SESSION = [];
		$hooks = new ScriptedHooks(['doActions' => ['code' => -1, 'error' => 'Hook failed', 'errors' => ['First error', 'Second error']]]);

		$vm = $this->controller($hooks)->show(GlobalsRequest::create('GET', '/user/1', ['group' => '3'], []), 1);

		self::assertSame('user:user_show', $vm->template);
		self::assertSame(['Hook failed', 'First error', 'Second error'], $_SESSION['dol_events']['errors']);
		$parameters = $hooks->parameters['doActions'];
		self::assertSame(1, $parameters['id']);
		self::assertSame(3, $parameters['group']);
		self::assertArrayHasKey('socid', $parameters);
		self::assertArrayHasKey('caneditgroup', $parameters);
		self::assertSame('usercard', $parameters['currentcontext']);
	}

	private function controller(?ScriptedHooks $scripted = null): UserController
	{
		$ctx = LegacyContext::fromGlobals();
		$connection = new Connection($ctx->db);
		$me = new LegacyCurrentUser($GLOBALS['user']);
		$perms = new UserPermissionChecker($me, false, false, false, [1], false, false, (int) $ctx->conf->entity);
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class]), '');
		$hm = $ctx->hookmanager;
		if ($scripted !== null) {
			$hm = new \HookManager($ctx->db);
			ScriptedHooks::register($hm, 'usercard', $scripted);
		}

		return new UserController(
			new UserRepository($connection, (int) $ctx->conf->entity),
			$perms,
			$me,
			new LegacyHooks($hm),
			new LegacyObjectLoader($connection),
			new LegacyTranslator($ctx->langs),
			new UserCardChrome($ctx, $urls),
			new FlashBag(),
			new UserService(new UserRepository($connection, (int) $ctx->conf->entity), new LegacyUserWriter($ctx, new LegacyObjectLoader($connection), $connection), new EventDispatcher(), $connection, $me),
			new UserValidator(new LegacyTranslator($ctx->langs), new LegacyConfig($ctx)),
			new UserFormChrome($ctx, $urls),
			new LegacyConfig($ctx),
			$urls,
			new Confirm($ctx)
		);
	}
}
