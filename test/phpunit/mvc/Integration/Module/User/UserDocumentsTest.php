<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\RouteDefinition;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Controller\UserListController;
use Dolibarr\Module\User\Documents\UserDocuments;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\UserModule;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

/**
 * The user side of the documents contract (htdocs/user/document.php), against the legacy admin user.
 */
final class UserDocumentsTest extends TestCase
{
	private LegacyContext $ctx;

	private mixed $phpSelf = null;

	protected function setUp(): void
	{
		$this->phpSelf = $_SERVER['PHP_SELF'] ?? null;
		$this->ctx = LegacyContext::fromGlobals();
		$this->ctx->langs->loadLangs(['main', 'users', 'other']);
		$conf = $this->ctx->conf;
		if (!isset($conf->browser->layout)) {
			$conf->browser->layout = 'classic';
		}
	}

	protected function tearDown(): void
	{
		$_SERVER['PHP_SELF'] = $this->phpSelf;
	}

	public function testDirectoryTitleAndChrome(): void
	{
		$legacyUser = new \User($this->ctx->db);
		self::assertGreaterThan(0, $legacyUser->fetch(1));
		$_SERVER['PHP_SELF'] = '/user/1/documents';
		$documents = new UserDocuments($this->ctx, new UserPermissionChecker(new RightsCurrentUser(1, true), false, false, false, [], false, false, 1));

		self::assertSame('user', $documents->modulePart());
		self::assertSame($this->ctx->conf->user->dir_output.'/1', $documents->directory($legacyUser));
		self::assertStringEndsWith('/users/1', $documents->directory($legacyUser));
		self::assertSame('', $documents->savingDocMask($legacyUser));

		// document.php l.153-154: "lastname, firstname - Documents", or the lastname alone
		$legacyUser->lastname = 'Doe';
		$legacyUser->firstname = 'Jane';
		self::assertSame('Doe, Jane - '.$this->ctx->langs->trans('Documents'), $documents->pageTitle($legacyUser));
		$legacyUser->firstname = '';
		self::assertSame('Doe - '.$this->ctx->langs->trans('Documents'), $documents->pageTitle($legacyUser));

		$chrome = $this->chromeWithRoutes([]);
		$parts = $chrome->chrome($legacyUser, new User(1, 1, 'admin'), 'document');
		self::assertMatchesRegularExpression('#id="document"#', $parts['tabsHtml']);
		self::assertMatchesRegularExpression('#class="tab tabactive"[^>]*><a id="document"#', $parts['tabsHtml']);
		self::assertStringContainsString('<td class="titlefield">'.$this->ctx->langs->trans('Login').'</td>', str_replace(' ', ' ', $parts['headerRowsHtml']));
		self::assertStringNotContainsString('NbOfAttachedFiles', $parts['headerRowsHtml']);
	}

	public function testDocumentTabKeepsItsLegacyUrlUntilTheRouteExists(): void
	{
		$legacyUser = new \User($this->ctx->db);
		$legacyUser->fetch(1);
		$_SERVER['PHP_SELF'] = '/user/1';
		$entity = new User(1, 1, 'admin');

		$without = $this->chromeWithRoutes([])->chrome($legacyUser, $entity, 'document')['tabsHtml'];
		self::assertStringContainsString('/user/document.php?userid=1', $without);

		$route = new RouteDefinition('entity_documents', '/{entity}/{id}/documents', ['GET'], UserController::class, 'show', [], false, null, ['entity', 'id']);
		$with = $this->chromeWithRoutes([$route])->chrome($legacyUser, $entity, 'document')['tabsHtml'];
		self::assertStringContainsString('href="/user/1/documents"', $with);
		self::assertStringNotContainsString('/user/document.php', $with);
	}

	public function testLegacyPageSettingsAreTheOnesOfDocumentPhp(): void
	{
		$settings = $this->chromeWithRoutes([])->legacyPageSettings('document');

		// document.php l.46, l.87, l.130 ($socid of the internal admin of the tests: 0)
		self::assertSame(['users', 'other'], $settings->translationDomains);
		self::assertSame(['usercard', 'userdoc', 'globalcard'], $settings->hookContexts);
		self::assertSame(['id' => 0], $settings->doActionsParams);
	}

	public function testTheModuleDeclaresTheDocumentsService(): void
	{
		$definition = (new UserModule())->entities()[0];

		self::assertSame(UserDocuments::class, $definition->documentsId);
	}

	/**
	 * @param list<RouteDefinition> $extraRoutes
	 */
	private function chromeWithRoutes(array $extraRoutes): UserCardChrome
	{
		$routes = (new RouteCompiler())->compile([UserController::class, UserListController::class]);

		return new UserCardChrome($this->ctx, new UrlGenerator([...$routes, ...$extraRoutes], ''));
	}
}
