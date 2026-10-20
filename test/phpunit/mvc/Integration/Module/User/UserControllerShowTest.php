<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyCurrentUser;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTranslator;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Entity\UserView;
use Dolibarr\Module\User\Entity\Visibility;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Module\User\View\UserCardHtml;
use PHPUnit\Framework\TestCase;

final class UserControllerShowTest extends TestCase
{
	private ?\stdClass $browser = null;

	protected function setUp(): void
	{
		// Browser detection is done by main.inc.php, not by master.inc.php used here: the banner reads it.
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
	}

	public function testShowBuildsViewModelWithEntityAndTabs(): void
	{
		$vm = $this->controller()->show(new Request('GET', '/user/1', [], [], [], []), 1);

		self::assertSame('user:user_show', $vm->template);
		$user = $vm->data['user'];
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
		$vm = $this->controller()->show(new Request('GET', '/user/1', [], [], [], []), 1);
		$card = $vm->data['card'];
		self::assertInstanceOf(UserCardHtml::class, $card);
		$langs = $GLOBALS['langs'];
		self::assertStringContainsString('action=edit', $card->actionsHtml);
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
		$this->controller()->show(new Request('GET', '/user/999999', [], [], [], []), 999999);
	}

	private function controller(): UserController
	{
		$ctx = LegacyContext::fromGlobals();
		$connection = new Connection($ctx->db);
		$me = new LegacyCurrentUser($GLOBALS['user']);
		$perms = new UserPermissionChecker($me, false, false, false, [1], false, false, (int) $ctx->conf->entity);
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class]), '');

		return new UserController(
			new UserRepository($connection, (int) $ctx->conf->entity),
			$perms,
			$me,
			new LegacyHooks($ctx->hookmanager),
			new LegacyObjectLoader($connection),
			new LegacyTranslator($ctx->langs),
			new UserCardChrome($ctx, $urls)
		);
	}
}
