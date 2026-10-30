<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\Documents;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityRegistry;
use Dolibarr\Core\Entity\EntityServices;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Files\EcmFileRepository;
use Dolibarr\Core\Files\ExternalLinkRepository;
use Dolibarr\Core\Files\FileIndex;
use Dolibarr\Core\Files\FileStorage;
use Dolibarr\Core\Files\Thumbnails;
use Dolibarr\Core\Files\UploadPolicy;
use Dolibarr\Core\Http\JsonResponse;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyCurrentUser;
use Dolibarr\Core\Legacy\LegacyEntityScope;
use Dolibarr\Core\Legacy\LegacyExtraFieldCatalog;
use Dolibarr\Core\Legacy\LegacyFileObjects;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyInputFilter;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTranslator;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Logger;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Core\Orm\Snapshots;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Module\Documents\Controller\DocumentsController;
use Dolibarr\Module\Documents\Service\AttachedFilesService;
use Dolibarr\Module\Documents\Service\LinkWriter;
use Dolibarr\Core\Entity\EntityDocuments;
use Dolibarr\Module\Documents\View\DocumentsChrome;
use Dolibarr\Module\Notes\Controller\NotesController;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Controller\UserListController;
use Dolibarr\Module\User\Documents\UserDocuments;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\UserModule;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use Dolibarr\Tests\Integration\Fixture\RenamingUploadedFileMover;
use Dolibarr\Tests\Integration\Fixture\ScriptedHooks;
use Dolibarr\Tests\Unit\Module\User\Fixture\RightsCurrentUser;
use PHPUnit\Framework\TestCase;

/**
 * The documents component on the user entity (htdocs/user/document.php, core/tpl/document_actions_post_headers.tpl.php,
 * core/actions_linkedfiles.inc.php). Every test runs in a transaction rolled back in tearDown; the disposable users
 * are created inside it, their documents directories removed in tearDown.
 */
final class DocumentsControllerTest extends TestCase
{
	use OverridesLegacyConf;

	private LegacyContext $context;

	private Connection $connection;

	private ?\stdClass $browser = null;

	/** @var array<mixed> */
	private array $session = [];

	/** @var array<mixed> */
	private array $get = [];

	/** @var array<mixed> */
	private array $post = [];

	/** @var array<mixed> */
	private array $server = [];

	/** @var list<string> */
	private array $createdDirectories = [];

	protected function setUp(): void
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';
		$this->get = $_GET;
		$this->post = $_POST;
		$this->server = $_SERVER;
		$this->session = $_SESSION ?? [];
		$_SESSION = [];
		$this->context = LegacyContext::fromGlobals();
		$this->connection = new Connection($this->context->db);
		$conf = $this->context->conf;
		$this->browser = $conf->browser;
		$conf->browser = clone $conf->browser;
		if (!isset($conf->browser->layout)) {
			$conf->browser->layout = 'classic';
		}
		$this->disableUserAgendaEvents();
		$this->setConf('MAIN_UPLOAD_DOC', '2048');
		$this->context->langs->loadLangs(['main', 'users', 'errors', 'other', 'link']);
		$this->connection->begin();
	}

	protected function tearDown(): void
	{
		$this->connection->rollback();
		$this->restoreConf();
		$this->context->conf->browser = $this->browser;
		$_SESSION = $this->session;
		$_GET = $this->get;
		$_POST = $this->post;
		$_SERVER = $this->server;
		foreach ($this->createdDirectories as $directory) {
			$this->removeTree($directory);
		}
	}

	public function testShowRendersTheLegacyBlocks(): void
	{
		$target = $this->disposable('Target');
		$this->putFile($target->id, 'report.txt', 'twelve bytes');
		$_SESSION['newtoken'] = 'session-token';

		$view = $this->controller()->show($this->request('GET', [], [], $target->id), 'user', $target->id);

		self::assertSame('documents:documents', $view->template);
		self::assertSame('mod-user page-card_document', $view->data['bodyCss']);
		self::assertSame('Target - '.$this->context->langs->trans('Documents'), $view->data['title']);
		self::assertSame('/user/'.$target->id.'/documents', $_SERVER['PHP_SELF']);
		// document.php l.166-206: the Login row, then the number and the total size of the files
		$rows = $view->data['chrome']['headerRowsHtml'];
		self::assertStringContainsString('<td class="titlefield">', $rows);
		self::assertStringContainsString('<tr><td>'.htmlspecialchars($this->text('NbOfAttachedFiles')).'</td><td>1</td></tr>', $rows);
		self::assertStringContainsString('<tr><td>'.htmlspecialchars($this->text('TotalSizeOfAttachedFiles')).'</td><td>'.dol_print_size(12, 1, 1).'</td></tr>', $rows);
		// The active tab points to the route
		self::assertMatchesRegularExpression('#id="document"[^>]+href="/user/'.$target->id.'/documents"|href="/user/'.$target->id.'/documents"[^>]*id="document"#', $view->data['chrome']['tabsHtml']);
		// The upload form of form_attach_new_file() inside the list of documents, posting to the route
		$documents = $view->data['fragments']['documents'];
		self::assertStringContainsString('id="formuserfile"', $documents);
		self::assertStringContainsString('action="/user/'.$target->id.'/documents?id='.$target->id.'&uploadform=1"', $documents);
		self::assertStringContainsString('report.txt', $documents);
		// app.php defines CSRFCHECK_WITH_TOKEN: the rename link of list_of_documents() gets the token the other action links have
		self::assertMatchesRegularExpression('#href="/user/'.$target->id.'/documents\?action=editfile&urlfile=[^"]*&token=session-token"#', $documents);
		self::assertDoesNotMatchRegularExpression('#href="[^"]*action=(editfile|deletefile)(?![^"]*token=)[^"]*"#', $documents);
		self::assertStringContainsString('id="formaddlink"', $view->data['fragments']['links']);
		self::assertSame('', $view->data['fragments']['confirm']);
		self::assertFalse($view->data['twoColumns']);
	}

	public function testCallerSavingMaskIsShownInTheUploadForm(): void
	{
		$target = $this->disposable('Masked');

		$view = $this->controller(null, null, 'REF-__file__')->show($this->request('GET', [], [], $target->id), 'user', $target->id);

		self::assertMatchesRegularExpression('#<input type="checkbox"[^>]*name="savingdocmask"[^>]*value="REF-__file__"#', $view->data['fragments']['attachForms']['upload']);
		self::assertStringContainsString('value="REF-__file__"', $view->data['fragments']['documents']);

		$this->setConf('MAIN_DISABLE_SUGGEST_REF_AS_PREFIX', '1');
		$disabled = $this->controller(null, null, 'REF-__file__')->show($this->request('GET', [], [], $target->id), 'user', $target->id);
		self::assertStringNotContainsString('name="savingdocmask"', $disabled->data['fragments']['attachForms']['upload']);
	}

	public function testUserHasNoSavingMask(): void
	{
		$target = $this->disposable('Unmasked');

		$view = $this->controller()->show($this->request('GET', [], [], $target->id), 'user', $target->id);

		self::assertStringNotContainsString('name="savingdocmask"', $view->data['fragments']['attachForms']['upload']);
	}

	public function testShowOfAnUnreadableUserIsDenied(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');

		$this->expectException(AccessDeniedException::class);
		$this->controller(new RightsCurrentUser($actor->id))->show($this->request('GET', [], [], $target->id), 'user', $target->id);
	}

	public function testUnknownEntityIs404(): void
	{
		$this->expectException(NotFoundException::class);
		$this->controller()->show($this->request('GET', [], [], 1), 'nothing', 1);
	}

	public function testDeletefileActionRendersThePopupPostingToTheRoute(): void
	{
		$target = $this->disposable('Target');
		$this->putFile($target->id, 'report.txt');
		$before = $GLOBALS['conf']->global->MAIN_MAX_URL_LENGTH_FOR_GET ?? null;

		$view = $this->controller()->show($this->request('GET', ['action' => 'deletefile', 'urlfile' => 'report.txt'], [], $target->id), 'user', $target->id);

		$confirm = $view->data['fragments']['confirm'];
		self::assertStringContainsString('dialog-confirm', $confirm);
		// Ruling R4: the Yes button posts (the max URL length of a GET forced to 1)
		self::assertStringContainsString('dolSubmitConfirmForm(urljump, pageyes, options, 1)', $confirm);
		self::assertStringContainsString('/user/'.$target->id.'/documents?id='.$target->id.'&urlfile=report.txt&linkid=0&id='.$target->id, $confirm);
		self::assertStringContainsString('confirm_deletefile', $confirm);
		self::assertSame($before, $GLOBALS['conf']->global->MAIN_MAX_URL_LENGTH_FOR_GET ?? null);
		self::assertFileExists($this->directoryOf($target->id).'/report.txt');
	}

	public function testPostWithoutWriteRightIsDenied(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');
		$this->putFile($target->id, 'report.txt');
		$reader = new RightsCurrentUser($actor->id, false, ['user.user.read']);

		try {
			$this->controller($reader)->act($this->post($target->id, ['action' => 'renamefile', 'renamefilesave' => 'Save', 'renamefilefrom' => 'report.txt', 'renamefileto' => 'other.txt']), 'user', $target->id);
			self::fail('AccessDeniedException expected');
		} catch (AccessDeniedException) {
			self::assertFileExists($this->directoryOf($target->id).'/report.txt');
		}

		$this->expectException(AccessDeniedException::class);
		$this->controller($reader)->act($this->post($target->id, ['action' => 'confirm_deletefile', 'confirm' => 'yes', 'urlfile' => 'report.txt']), 'user', $target->id);
	}

	public function testPostRenameRedirectsWithFlash(): void
	{
		$target = $this->disposable('Target');
		$this->putFile($target->id, 'report.txt');

		$response = $this->controller()->act($this->post($target->id, [
			'action' => 'renamefile',
			'renamefilesave' => 'Save',
			'renamefilefrom' => 'report.txt',
			'renamefileto' => 'renamed.txt',
			'sortfield' => 'name',
			'sortorder' => 'desc',
		]), 'user', $target->id);

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/'.$target->id.'/documents?sortfield=name&sortorder=desc', $response->headers()['Location']);
		self::assertFileDoesNotExist($this->directoryOf($target->id).'/report.txt');
		self::assertFileExists($this->directoryOf($target->id).'/renamed.txt');
		self::assertSame([$this->text('FileRenamed')], $_SESSION['dol_events']['mesgs'] ?? []);
	}

	public function testPostDeleteFileAnswersJson(): void
	{
		$target = $this->disposable('Target');
		$this->putFile($target->id, 'report.txt');
		$_SERVER['HTTP_ACCEPT'] = 'application/json';

		$response = $this->controller()->act($this->post($target->id, ['action' => 'confirm_deletefile', 'confirm' => 'yes', 'urlfile' => 'report.txt']), 'user', $target->id);

		self::assertInstanceOf(JsonResponse::class, $response);
		$body = json_decode($response->body(), true);
		self::assertSame(true, $body['ok']);
		self::assertSame([['type' => 'mesgs', 'text' => $this->text('FileWasRemoved', 'report.txt')]], $body['messages']);
		self::assertFileDoesNotExist($this->directoryOf($target->id).'/report.txt');
		self::assertSame([], $_SESSION['dol_events'] ?? []);
	}

	public function testJsonListsFilesAndLinks(): void
	{
		$actor = $this->disposable('Actor');
		$target = $this->disposable('Target');
		$this->putFile($target->id, 'shared.txt', 'shared content');
		$this->putFile($target->id, 'loose.png', 'not an image');
		$legacyTarget = $this->legacyUser($target->id);
		self::assertGreaterThan(0, addFileIntoDatabaseIndex($this->directoryOf($target->id), 'shared.txt', '', 'uploaded', 1, $legacyTarget));
		$linkId = $this->legacyLink($target->id, 'https://example.com/doc', 'Example');

		$reader = new RightsCurrentUser($actor->id, false, ['user.user.read']);
		$view = $this->controller($reader)->show($this->request('GET', [], [], $target->id, true), 'user', $target->id);

		$files = $view->exposed['files'];
		self::assertCount(2, $files);
		// Default order position_name: the indexed file first, the unindexed one at position 999999
		self::assertSame(['shared.txt', 'loose.png'], array_column($files, 'name'));
		$shared = $files[0];
		self::assertSame(14, $shared['size']);
		self::assertTrue($shared['shared']);
		self::assertNull($shared['shareUrl'], 'R8: no share URL for a reader');
		// list_of_documents(): the entity of the object when not empty, the relative path <ref>/<name>
		$entityParameter = empty($legacyTarget->entity) ? '' : '&entity='.((int) $legacyTarget->entity);
		self::assertSame(DOL_URL_ROOT.'/document.php?modulepart=user'.$entityParameter.'&file='.urlencode($target->id.'/shared.txt'), $shared['downloadUrl']);
		self::assertNull($shared['previewUrl']);
		self::assertSame(999999, $files[1]['position']);
		self::assertFalse($files[1]['shared']);
		self::assertStringContainsString('/viewimage.php?modulepart=user', (string) $files[1]['previewUrl']);
		self::assertSame([['id' => $linkId, 'url' => 'https://example.com/doc', 'label' => 'Example', 'shared' => false]], $view->exposed['links']);

		// A writer gets the share URL
		$view = $this->controller()->show($this->request('GET', [], [], $target->id, true), 'user', $target->id);
		self::assertStringContainsString('/document.php?hashp=', (string) $view->exposed['files'][0]['shareUrl']);
	}

	public function testGetConfirmDeletefileWritesNothing(): void
	{
		$target = $this->disposable('Target');
		$this->putFile($target->id, 'report.txt');

		$this->controller()->show($this->request('GET', ['action' => 'confirm_deletefile', 'confirm' => 'yes', 'urlfile' => 'report.txt'], [], $target->id), 'user', $target->id);

		self::assertFileExists($this->directoryOf($target->id).'/report.txt');
	}

	public function testInvalidTokenWritesNothing(): void
	{
		$target = $this->disposable('Target');
		$this->putFile($target->id, 'report.txt');
		$hooks = new ScriptedHooks([]);
		$countLinks = fn (): int => (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @links WHERE objecttype = :type AND objectid = :id', ['type' => 'user', 'id' => $target->id]);

		// main.inc.php on an invalid token sets errorcode; a write payload still present must not be run
		$response = $this->controller(null, $hooks)->act($this->request(
			'POST',
			['errorcode' => 'InvalidToken', 'linkit' => 'Link', 'link' => 'https://example.com/doc', 'label' => 'Doc'],
			['action' => 'confirm_deletefile', 'confirm' => 'yes', 'urlfile' => 'report.txt', 'token' => 'invalid'],
			$target->id
		), 'user', $target->id);

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/user/'.$target->id.'/documents', $response->headers()['Location']);
		self::assertSame('', $hooks->actions['doActions']);
		self::assertFileExists($this->directoryOf($target->id).'/report.txt');
		self::assertSame(0, $countLinks());

		// The same payload with a valid token writes: the test discriminates the InvalidToken branch
		$this->controller()->act($this->request(
			'POST',
			['linkit' => 'Link', 'link' => 'https://example.com/doc', 'label' => 'Doc'],
			['action' => 'confirm_deletefile', 'confirm' => 'yes', 'urlfile' => 'report.txt', 'token' => 'checked-by-main-inc'],
			$target->id
		), 'user', $target->id);
		self::assertFileDoesNotExist($this->directoryOf($target->id).'/report.txt');
		self::assertSame(1, $countLinks());
	}

	public function testTwoColumnsFollowsTheLegacyTruthiness(): void
	{
		$target = $this->disposable('Target');

		foreach (['0' => false, '' => false, '1' => true] as $value => $expected) {
			$this->setConf('MAIN_DOCUMENTS_LIST_IN_TWOCOLUMNS', (string) $value);
			$view = $this->controller()->show($this->request('GET', [], [], $target->id), 'user', $target->id);
			self::assertSame($expected, $view->data['twoColumns'], 'MAIN_DOCUMENTS_LIST_IN_TWOCOLUMNS = \''.$value.'\'');
		}
	}

	public function testUserOfEntityZeroCanShareAFileIndexedByTheListing(): void
	{
		$target = $this->disposable('Admin');
		// A superadmin (entity 0), as the default admin: its files are written with the entity 0
		$this->connection->execute('UPDATE @user SET entity = 0 WHERE rowid = :id', ['id' => $target->id]);
		$this->putFile($target->id, 'first.txt');
		$entity = (int) $this->context->conf->entity;
		[$files] = $this->repositories();

		// The page lists the file: list_of_documents() indexes it with the current entity
		$this->controller()->show($this->request('GET', [], [], $target->id), 'user', $target->id);
		$this->controller()->act($this->post($target->id, ['action' => 'renamefile', 'renamefilesave' => 'Save', 'renamefilefrom' => 'first.txt', 'renamefileto' => 'second.txt']), 'user', $target->id);
		$this->controller()->show($this->request('GET', [], [], $target->id), 'user', $target->id);
		$row = $files->findByPath($entity, 'users/'.$target->id, 'second.txt');
		self::assertNotNull($row, 'indexed by the listing with the current entity');

		// The rename form of that file, its checkbox ticked, then unticked
		$response = $this->controller()->act($this->post($target->id, ['action' => 'renamefile', 'renamefilesave' => 'Save', 'renamefilefrom' => 'second.txt', 'renamefileto' => 'second.txt', 'ecmfileid' => (string) $row->id, 'shareenabled' => 'on']), 'user', $target->id);
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertNotEmpty($files->find((int) $row->id)?->share);

		$this->controller()->act($this->post($target->id, ['action' => 'renamefile', 'renamefilesave' => 'Save', 'renamefilefrom' => 'second.txt', 'renamefileto' => 'second.txt', 'ecmfileid' => (string) $row->id]), 'user', $target->id);
		self::assertEmpty($files->find((int) $row->id)?->share);
		self::assertSame([], $_SESSION['dol_events']['errors'] ?? []);
	}

	public function testDoActionsVetoWritesNothing(): void
	{
		$target = $this->disposable('Target');
		$this->putFile($target->id, 'report.txt');
		$hooks = new ScriptedHooks(['doActions' => ['code' => 1]]);

		$this->controller(null, $hooks)->act($this->post($target->id, ['action' => 'confirm_deletefile', 'confirm' => 'yes', 'urlfile' => 'report.txt']), 'user', $target->id);

		self::assertSame('confirm_deletefile', $hooks->actions['doActions']);
		// document.php l.130: array('id' => $socid)
		self::assertSame(0, $hooks->parameters['doActions']['id'] ?? null);
		self::assertFileExists($this->directoryOf($target->id).'/report.txt');
	}

	public function testUploadFormWithoutBodyIsFileTooLarge(): void
	{
		$target = $this->disposable('Target');

		// Ruling R6: post_max_size exceeded, PHP empties $_POST and $_FILES
		$response = $this->controller()->act($this->request('POST', ['uploadform' => '1'], [], $target->id), 'user', $target->id);

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame([$this->text('ErrorFileSizeTooLarge'), $this->text('ErrorGoBackAndCorrectParameters')], $_SESSION['dol_events']['errors'] ?? []);
	}

	/**
	 * The text of a message as the browser shows it (\Translate::trans() encodes the accents, the Translator does not).
	 */
	private function text(string $key, string ...$parameters): string
	{
		return trim(html_entity_decode($this->context->langs->trans($key, ...$parameters), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	private function controller(?CurrentUser $me = null, ?ScriptedHooks $scripted = null, ?string $mask = null): DocumentsController
	{
		$context = $this->context;
		$me ??= $this->legacyMe();
		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class, UserListController::class, NotesController::class, DocumentsController::class]), '');
		$hookManager = $context->hookmanager;
		if ($scripted !== null) {
			$hookManager = new \HookManager($context->db);
			ScriptedHooks::register($hookManager, 'userdoc', $scripted);
		}
		$hooks = new LegacyHooks($hookManager);
		$checker = new UserPermissionChecker($me, false, false, false, [], false, false, (int) $context->conf->entity);
		$container = new Container();
		$container->instance(UserRepository::class, $this->repo());
		$container->instance(UserPermissionChecker::class, $checker);
		$container->instance(UserCardChrome::class, new UserCardChrome($context, $urls));
		$userDocuments = new UserDocuments($context, $checker);
		$container->instance(UserDocuments::class, $mask === null ? $userDocuments : new class($userDocuments, $mask) implements EntityDocuments {
			public function __construct(private readonly UserDocuments $inner, private readonly string $mask)
			{
			}

			public function modulePart(): string
			{
				return $this->inner->modulePart();
			}

			public function directory(\CommonObject $legacyObject): string
			{
				return $this->inner->directory($legacyObject);
			}

			public function canWriteDocuments(object $entity): bool
			{
				return $this->inner->canWriteDocuments($entity);
			}

			public function pageTitle(\CommonObject $legacyObject): string
			{
				return $this->inner->pageTitle($legacyObject);
			}

			public function savingDocMask(\CommonObject $legacyObject): string
			{
				return $this->mask;
			}
		});

		[$files, $links] = $this->repositories();
		$config = new LegacyConfig($context);
		$triggers = new LegacyTriggers($context);
		$objects = new LegacyFileObjects($context->db);
		$policy = new UploadPolicy();
		$storage = new FileStorage($hooks, new RenamingUploadedFileMover(), $policy);
		$service = new AttachedFilesService(
			$storage,
			new FileIndex($files, $this->connection, $triggers, $objects, $config, $context),
			new Thumbnails($storage),
			$policy,
			new LinkWriter($links, $this->connection, $triggers, $objects, $config, $context),
			$links,
			$files,
			$hooks,
			new LegacyTranslator($context->langs),
			$config
		);

		return new DocumentsController(
			$this->registry(),
			new EntityServices($container),
			$hooks,
			new LegacyObjectLoader($this->connection),
			$service,
			new DocumentsChrome($context, $hooks),
			$files,
			$links,
			new FlashBag(),
			new LegacyTranslator($context->langs),
			$urls,
			$config
		);
	}

	/**
	 * @return array{EcmFileRepository, ExternalLinkRepository}
	 */
	private function repositories(): array
	{
		$hydrator = new Hydrator();
		$orm = new Orm(
			$this->connection,
			new MappingRegistry(),
			new LegacyEntityScope(),
			$hydrator,
			new Snapshots($hydrator),
			new LegacyExtraFieldCatalog($this->connection, new LegacyConfig($this->context), new Logger())
		);

		return [(new EcmFileRepository($orm))->withoutEntityScope(), (new ExternalLinkRepository($orm))->withoutEntityScope()];
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
	private function request(string $method, array $get, array $post, int $id, bool $json = false): Request
	{
		$_GET = $get;
		$_POST = $post;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI'] = '/user/'.$id.'/documents';
		if ($json) {
			$_SERVER['HTTP_ACCEPT'] = 'application/json';
		}

		return Request::fromGlobals('')->withInputFilter(new LegacyInputFilter());
	}

	private function legacyMe(): CurrentUser
	{
		$legacyUser = $this->context->user;
		self::assertNotNull($legacyUser);

		return new LegacyCurrentUser($legacyUser);
	}

	private function disposable(string $lastname): User
	{
		$loader = new LegacyObjectLoader($this->connection);
		$service = new UserService($this->repo(), new LegacyUserWriter($this->context, $loader, $this->connection), new EventDispatcher(), $this->connection, $this->legacyMe());
		$created = $service->create(new User(0, 1, 'mvc_test_'.uniqid(), lastname: $lastname), 'Secret-Pass-123!', []);
		$this->createdDirectories[] = $this->directoryOf($created->id);

		return $created;
	}

	private function legacyUser(int $id): \User
	{
		$legacyUser = new \User($this->context->db);
		self::assertGreaterThan(0, $legacyUser->fetch($id));

		return $legacyUser;
	}

	private function legacyLink(int $objectId, string $url, string $label): int
	{
		$link = new \Link($this->context->db);
		$link->entity = (int) $this->context->conf->entity;
		$link->url = $url;
		$link->objecttype = 'user';
		$link->objectid = $objectId;
		$link->label = $label;
		$linkId = $link->create($GLOBALS['user']);
		self::assertGreaterThan(0, $linkId);

		return (int) $linkId;
	}

	private function directoryOf(int $id): string
	{
		return $this->context->conf->user->dir_output.'/'.$id;
	}

	private function putFile(int $id, string $name, string $content = 'content'): void
	{
		$directory = $this->directoryOf($id);
		if (!is_dir($directory)) {
			mkdir($directory, 0777, true);
		}
		file_put_contents($directory.'/'.$name, $content);
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
		return new UserRepository($this->connection, (int) $this->context->conf->entity);
	}

	private function removeTree(string $directory): void
	{
		if (!is_dir($directory)) {
			return;
		}
		foreach (glob($directory.'/{*,.[!.]*}', GLOB_BRACE) ?: [] as $entry) {
			if (is_dir($entry) && !is_link($entry)) {
				$this->removeTree($entry);
			} else {
				unlink($entry);
			}
		}
		rmdir($directory);
	}
}
