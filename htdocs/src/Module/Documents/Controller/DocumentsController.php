<?php

declare(strict_types=1);

namespace Dolibarr\Module\Documents\Controller;

use Dolibarr\Core\Config;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Entity\EntityDocuments;
use Dolibarr\Core\Entity\EntityRegistry;
use Dolibarr\Core\Entity\EntityServices;
use Dolibarr\Core\Entity\EntityTabs;
use Dolibarr\Core\Entity\LegacyPageSettings;
use Dolibarr\Core\Files\EcmFile;
use Dolibarr\Core\Files\EcmFileRepository;
use Dolibarr\Core\Files\ExternalLinkRepository;
use Dolibarr\Core\Http\JsonResponse;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\HookResult;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Routing\Route;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Core\Translator;
use Dolibarr\Core\View\ViewModel;
use Dolibarr\Module\Documents\Service\ActionResult;
use Dolibarr\Module\Documents\Service\AttachedFilesService;
use Dolibarr\Module\Documents\Service\DocumentsTarget;
use Dolibarr\Module\Documents\View\DocumentsChrome;

/**
 * The attached files tab of any declared entity whose definition names an EntityDocuments: the view of its legacy
 * document page (htdocs/user/document.php for the user, with core/tpl/document_actions_post_headers.tpl.php) and its
 * actions, core/actions_linkedfiles.inc.php, run by AttachedFilesService.
 *
 * A GET never writes: the legacy display actions (editfile, deletefile, deletelink, update) are served by show(), the
 * writing ones are POSTed to act(), which answers with a redirect to the page (messages in flash) or, in JSON,
 * {"ok": bool, "messages": [{"type": "mesgs|warnings|errors", "text": "…"}]} (ruling R5).
 */
final class DocumentsController
{
	/** The sort fields of the list of files (list_of_documents() titles and the default of the legacy page) */
	private const SORT_FIELDS = ['name', 'size', 'date', 'position_name'];

	/** document.php l.170: the files listed are those of the directory, without the .meta and the PDF previews */
	private const EXCLUDED_FILES = '(\.meta|_preview.*\.png)$';

	/** completeFileArrayWithDatabaseInfo(): the position of a file the index does not know */
	private const UNINDEXED_POSITION = 999999;

	public function __construct(
		private readonly EntityRegistry $registry,
		private readonly EntityServices $services,
		private readonly LegacyHooks $hooks,
		private readonly LegacyObjectLoader $legacy,
		private readonly AttachedFilesService $attachedFiles,
		private readonly DocumentsChrome $chrome,
		private readonly EcmFileRepository $files,
		private readonly ExternalLinkRepository $links,
		private readonly FlashBag $flash,
		private readonly Translator $translator,
		private readonly UrlGenerator $urls,
		private readonly Config $config
	) {
	}

	/**
	 * The documents tab (document.php l.104-217): the header with the number and the size of the files, the forms to
	 * upload a file and to add a link, the list of the files and the list of the links; with ?action=deletefile or
	 * deletelink the confirmation popup, ?action=editfile the renaming of a file, ?action=update the edition of a link.
	 * doActions runs as in document.php, but a GET never writes. In JSON: the files and the links.
	 *
	 * @throws NotFoundException     When the entity type, the entity or its attached files do not exist
	 * @throws AccessDeniedException When the current user may not read the entity
	 * @throws DatabaseException
	 * @throws \InvalidArgumentException
	 */
	#[Route('/{entity}/{id}/documents', methods: ['GET'], name: 'entity_documents', requirements: ['entity' => '[a-z]+', 'id' => '\d+'])]
	public function show(Request $request, string $entity, int $id): ViewModel
	{
		[$definition, $found, $documents, $tabs, $settings, $target] = $this->prepare($entity, $id);
		$action = $this->param($request, 'action', 'aZ09');
		$doActions = $this->doActions($settings, $target->legacyObject, $action);
		if ($doActions->failed()) {
			foreach ($doActions->errors as $error) {
				$this->flash->error($error);
			}
		}

		// The FormFile helpers build their forms and links on PHP_SELF: the route path, as for the other tabs
		$_SERVER['PHP_SELF'] = $this->urls->generate('entity_documents', ['entity' => $definition->slug, 'id' => $id]);

		[$sortfield, $sortorder] = $this->sorting($request);
		$fileArray = $this->fileArray($target, $sortfield, $sortorder);
		$exposedFiles = $this->exposedFiles($target, $fileArray, $sortfield, $sortorder);
		$exposedLinks = $this->exposedLinks($target);

		$data = [];
		if (!$request->wantsJson()) {
			$chrome = $tabs->chrome($target->legacyObject, $found, 'document');
			$chrome['headerRowsHtml'] .= $this->attachedFilesRows($fileArray);
			$data = [
				'outputBeforeHeader' => $doActions->output,
				'title' => $documents->pageTitle($target->legacyObject),
				'bodyCss' => 'mod-'.$definition->element.' page-card_document',
				// document_actions_post_headers.tpl.php l.181: if (getDolGlobalString(...)), '0' is one column
				'twoColumns' => !in_array($this->config->string('MAIN_DOCUMENTS_LIST_IN_TWOCOLUMNS'), ['', '0'], true),
				'chrome' => $chrome,
				'fragments' => $this->chrome->fragments($target, $action, $sortfield, $sortorder, $documents->savingDocMask($target->legacyObject)),
			];
		}

		return (new ViewModel('documents:documents', $data))
			->expose('files', $exposedFiles)
			->expose('links', $exposedLinks);
	}

	/**
	 * The actions of actions_linkedfiles.inc.php: upload (sendit), link (linkit), deletion of a file or a link
	 * (confirm_deletefile), update of a link (confirm_updateline), rename and sharing of a file (renamefile).
	 *
	 * @throws NotFoundException     When the entity type, the entity or its attached files do not exist
	 * @throws AccessDeniedException When the current user may not read the entity, or may not write its documents
	 *                               for a writing action, or when the file or the link is the one of another object
	 * @throws DatabaseException
	 * @throws \LogicException
	 * @throws \InvalidArgumentException
	 */
	#[Route('/{entity}/{id}/documents', methods: ['POST'], name: 'entity_documents_post', requirements: ['entity' => '[a-z]+', 'id' => '\d+'])]
	public function act(Request $request, string $entity, int $id): RedirectResponse|JsonResponse
	{
		[$definition, , , , $settings, $target] = $this->prepare($entity, $id);
		foreach (['errors', 'link'] as $domain) {
			$this->translator->load($domain);
		}
		$result = new ActionResult();

		// Invalid CSRF token (main.inc.php l.456-478, its warning already set): doActions with an empty action, nothing written
		if ($request->get('errorcode', 'aZ09') === 'InvalidToken') {
			$this->doActions($settings, $target->legacyObject, '');
			$result->ok = false;

			return $this->respond($request, $definition, $id, $result);
		}

		// Ruling R6, actions_linkedfiles.inc.php l.48-55: post_max_size exceeded, PHP emptied the body and the files
		if ($request->getInt('uploadform') !== 0 && !$request->hasBody()) {
			$this->translator->load('install');
			$result->add('errors', $this->translator->trans('ErrorFileSizeTooLarge'));
			$result->add('errors', $this->translator->trans('ErrorGoBackAndCorrectParameters'));

			return $this->respond($request, $definition, $id, $result);
		}

		// document.php l.130-137: the standard actions only when doActions returns 0
		$action = $this->param($request, 'action', 'aZ09');
		$doActions = $this->doActions($settings, $target->legacyObject, $action);
		$result->addAll('errors', '', $doActions->failed() ? $doActions->errors : []);
		if ($doActions->code !== 0 || $this->param($request, 'cancel', 'alpha') !== '') {
			return $this->respond($request, $definition, $id, $result);
		}

		$confirm = $this->param($request, 'confirm', 'alphanohtml');
		if (!$this->isWriteAction($request, $action, $confirm)) {
			return $this->respond($request, $definition, $id, $result);
		}
		// actions_linkedfiles.inc.php l.56-63: a writing action needs $permissiontoadd
		if (!$target->canWrite) {
			throw new AccessDeniedException();
		}

		// A failing ECMFILES_* or LINK_* trigger is reported by the service in the result (FileTriggerException caught there)
		$this->dispatch($request, $target, $action, $confirm, $result);

		return $this->respond($request, $definition, $id, $result);
	}

	/**
	 * actions_linkedfiles.inc.php l.65-375, in the same order and with the same parameter filters.
	 *
	 * @throws AccessDeniedException
	 * @throws DatabaseException
	 * @throws \LogicException
	 * @throws \InvalidArgumentException
	 */
	private function dispatch(Request $request, DocumentsTarget $target, string $action, string $confirm, ActionResult $result): void
	{
		// l.65-150
		if ($this->param($request, 'sendit', 'alpha') !== '') {
			self::merge($result, $this->attachedFiles->upload(
				$target,
				$request->uploadedFiles('userfile'),
				$request->getInt('overwritefile') !== 0,
				$this->param($request, 'savingdocmask', 'alpha')
			));
		} elseif ($this->param($request, 'linkit', 'restricthtml') !== '') {
			self::merge($result, $this->attachedFiles->addLink($target, $this->param($request, 'link', 'alpha'), $this->param($request, 'label', 'alpha')));
		}

		// l.153-205
		if ($action === 'confirm_deletefile' && $confirm === 'yes') {
			$urlFile = $this->param($request, 'urlfile', 'alpha');
			$linkId = $request->getInt('linkid');
			if ($urlFile !== '') {
				self::merge($result, $this->attachedFiles->deleteFile($target, $urlFile));
			} elseif ($linkId !== 0) {
				self::merge($result, $this->attachedFiles->deleteLink($target, $linkId));
			}
		} elseif ($action === 'confirm_updateline' && $this->param($request, 'save', 'alpha') !== '' && $this->param($request, 'link', 'alpha') !== '') {
			// l.206-245
			self::merge($result, $this->attachedFiles->updateLink(
				$target,
				$request->getInt('linkid'),
				$this->param($request, 'link', 'alpha'),
				$this->param($request, 'label', 'alphanohtml'),
				$this->param($request, 'shareenabled', 'alpha') !== ''
			));
		} elseif ($action === 'renamefile' && $this->param($request, 'renamefilesave', 'alpha') !== '') {
			// l.246-375: the rename, then the sharing of the same form
			self::merge($result, $this->attachedFiles->rename($target, $this->param($request, 'renamefilefrom', 'alpha'), $this->param($request, 'renamefileto', 'alpha')));
			$ecmFileId = $request->getInt('ecmfileid');
			if ($ecmFileId > 0) {
				self::merge($result, $this->attachedFiles->share($target, $ecmFileId, $this->param($request, 'shareenabled', 'alpha') !== ''));
			}
		}
	}

	/**
	 * actions_linkedfiles.inc.php l.56-60: the actions that need the right to write the documents.
	 */
	private function isWriteAction(Request $request, string $action, string $confirm): bool
	{
		return $this->param($request, 'sendit', 'alpha') !== ''
			|| $this->param($request, 'linkit', 'restricthtml') !== ''
			|| ($action === 'confirm_deletefile' && $confirm === 'yes')
			|| ($action === 'confirm_updateline' && $this->param($request, 'save', 'alpha') !== '' && $this->param($request, 'link', 'alpha') !== '')
			|| ($action === 'renamefile' && $this->param($request, 'renamefilesave', 'alpha') !== '');
	}

	/**
	 * Ruling R5: the result in JSON, or its messages in flash and a redirection to the page, with its sort kept.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function respond(Request $request, EntityDefinition $definition, int $id, ActionResult $result): RedirectResponse|JsonResponse
	{
		if ($request->wantsJson()) {
			return new JsonResponse(['ok' => $result->ok, 'messages' => $result->messages]);
		}

		foreach ($result->messages as $message) {
			match ($message['type']) {
				'mesgs' => $this->flash->success($message['text']),
				'warnings' => $this->flash->warning($message['text']),
				'errors' => $this->flash->error($message['text']),
			};
		}

		$parameters = ['entity' => $definition->slug, 'id' => $id];
		foreach (['sortfield', 'sortorder'] as $name) {
			$value = $this->param($request, $name, 'aZ09comma');
			if ($value !== '') {
				$parameters[$name] = $value;
			}
		}

		return new RedirectResponse($this->urls->generate('entity_documents', $parameters));
	}

	/**
	 * The definition, the entity readable by the current user, its attached files, its tabs, the settings of its
	 * legacy document page (domains loaded and hook contexts initialised here) and the target of the actions.
	 *
	 * @return array{EntityDefinition, object, EntityDocuments, EntityTabs, LegacyPageSettings, DocumentsTarget}
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 * @throws \LogicException
	 */
	private function prepare(string $slug, int $id): array
	{
		if (!$this->registry->has($slug)) {
			throw new NotFoundException('Unknown entity type '.$slug);
		}
		$definition = $this->registry->get($slug);
		$documents = $this->services->documents($definition);
		if ($documents === null) {
			throw new NotFoundException('The entity type '.$slug.' has no attached files');
		}

		$found = $this->services->repository($definition)->find($id);
		if ($found === null) {
			throw new NotFoundException($slug.' '.$id.' not found');
		}
		if (!$this->services->permissions($definition)->canRead($found)) {
			throw new AccessDeniedException();
		}

		$tabs = $this->services->tabs($definition);
		$settings = $tabs->legacyPageSettings('document');
		foreach ($settings->translationDomains as $domain) {
			$this->translator->load($domain);
		}
		$this->hooks->init($settings->hookContexts);

		$legacyObject = $this->legacy->byElement($definition, $id);
		if ($legacyObject === null) {
			throw new NotFoundException($slug.' '.$id.' not found');
		}
		$target = new DocumentsTarget(
			$definition,
			$legacyObject,
			$documents->directory($legacyObject),
			$documents->modulePart(),
			$documents->canWriteDocuments($found)
		);

		return [$definition, $found, $documents, $tabs, $settings, $target];
	}

	/**
	 * The doActions hook of the legacy document page (document.php l.130-134), with its parameters.
	 */
	private function doActions(LegacyPageSettings $settings, \CommonObject $legacyObject, string $action): HookResult
	{
		$object = $legacyObject;

		return $this->hooks->execute('doActions', $settings->doActionsParams, $object, $action);
	}

	/**
	 * document.php l.88-102, restricted to the fields of the list (default position_name ASC).
	 *
	 * @return array{string, string}
	 */
	private function sorting(Request $request): array
	{
		$sortfield = $this->param($request, 'sortfield', 'aZ09comma');
		$sortorder = $this->param($request, 'sortorder', 'aZ09comma');

		return [
			in_array($sortfield, self::SORT_FIELDS, true) ? $sortfield : 'position_name',
			in_array(strtolower($sortorder), ['asc', 'desc'], true) ? $sortorder : 'ASC',
		];
	}

	/**
	 * document.php l.170: the files of the directory.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function fileArray(DocumentsTarget $target, string $sortfield, string $sortorder): array
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		return array_values(dol_dir_list($target->directory, 'files', 0, '', self::EXCLUDED_FILES, $sortfield, strtolower($sortorder) === 'desc' ? SORT_DESC : SORT_ASC, 1));
	}

	/**
	 * document.php l.171-210: the number and the total size of the files, after the rows of the entity.
	 *
	 * @param list<array<string, mixed>> $fileArray
	 */
	private function attachedFilesRows(array $fileArray): string
	{
		$totalSize = 0;
		foreach ($fileArray as $file) {
			$totalSize += (int) ($file['size'] ?? 0);
		}

		return '<tr><td>'.htmlspecialchars($this->translator->trans('NbOfAttachedFiles')).'</td><td>'.count($fileArray).'</td></tr>'
			.'<tr><td>'.htmlspecialchars($this->translator->trans('TotalSizeOfAttachedFiles')).'</td><td>'.dol_print_size($totalSize, 1, 1).'</td></tr>';
	}

	/**
	 * The files as list_of_documents() lists them: completed with their index row (position, sharing; a file the
	 * index does not know is at position 999999 and not shared), sorted the same way, with the download and preview
	 * links of the list. The share URL only for a user who may write the documents (ruling R8).
	 *
	 * @param list<array<string, mixed>> $fileArray
	 * @return list<array{name: string, size: int, date: string, position: int, shared: bool, downloadUrl: string, previewUrl: ?string, shareUrl: ?string}>
	 * @throws \InvalidArgumentException
	 */
	private function exposedFiles(DocumentsTarget $target, array $fileArray, string $sortfield, string $sortorder): array
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';

		$indexRows = [];
		foreach ($this->files->inDirectory($target->listingEntity(), $target->relativeDirectory()) as $row) {
			$indexRows[$row->filename] ??= $row;
		}

		$completed = [];
		foreach ($fileArray as $file) {
			$name = (string) ($file['name'] ?? '');
			$row = $indexRows[(string) preg_replace('/\.noexe$/', '', $name)] ?? null;
			$position = $row === null ? self::UNINDEXED_POSITION : (int) $row->position;
			$file['position'] = $position;
			$file['position_name'] = ($position !== 0 ? (string) $position : '0').'_'.$name;
			$file['indexRow'] = $row;
			$completed[] = $file;
		}
		$completed = array_values(dol_sort_array($completed, $sortfield, $sortorder, 1));

		$exposed = [];
		foreach ($completed as $file) {
			$row = $file['indexRow'];
			$name = (string) $file['name'];
			$exposed[] = [
				'name' => $name,
				'size' => (int) ($file['size'] ?? 0),
				'date' => date(DATE_ATOM, (int) ($file['date'] ?? 0)),
				'position' => (int) $file['position'],
				'shared' => $row instanceof EcmFile && (string) $row->share !== '',
				'downloadUrl' => $this->downloadUrl($target, $name),
				'previewUrl' => $this->previewUrl($target, $name),
				'shareUrl' => $target->canWrite && $row instanceof EcmFile ? $this->shareUrl($row) : null,
			];
		}

		return $exposed;
	}

	/**
	 * @return list<array{id: int, url: string, label: string, shared: bool}>
	 * @throws \InvalidArgumentException
	 */
	private function exposedLinks(DocumentsTarget $target): array
	{
		$exposed = [];
		foreach ($this->links->forObject((string) $target->legacyObject->element, (int) $target->legacyObject->id, $this->config->entity()) as $link) {
			$exposed[] = ['id' => (int) $link->id, 'url' => $link->url, 'label' => $link->label, 'shared' => (string) $link->share !== ''];
		}

		return $exposed;
	}

	/**
	 * list_of_documents(): the relative path of the files of the object, <ref>/ (<id>/ for a third party).
	 */
	private static function relativePath(DocumentsTarget $target): string
	{
		$object = $target->legacyObject;
		if ((string) $object->element === 'societe' && !empty($object->id)) {
			return $object->id.'/';
		}

		return (!empty($object->ref) ? dol_sanitizeFileName((string) $object->ref) : '').'/';
	}

	/**
	 * list_of_documents(): document.php with the module part, the entity of the object when not empty, the file.
	 */
	private function downloadUrl(DocumentsTarget $target, string $name): string
	{
		$entity = (int) ($target->legacyObject->entity ?? 0);

		return $this->config->urlRoot().'/document.php?modulepart='.urlencode($target->modulePart)
			.($entity !== 0 ? '&entity='.$entity : '')
			.'&file='.urlencode(self::relativePath($target).$name);
	}

	/**
	 * list_of_documents(): viewimage.php for an image, with the entity of the object (the current one when empty) and
	 * the extension in lower case.
	 */
	private function previewUrl(DocumentsTarget $target, string $name): ?string
	{
		if (image_format_supported($name) < 0) {
			return null;
		}
		$fileInfo = pathinfo($name);
		$entity = (int) ($target->legacyObject->entity ?? 0);

		return $this->config->urlRoot().'/viewimage.php?modulepart='.urlencode($target->modulePart)
			.'&entity='.($entity !== 0 ? $entity : $this->config->entity())
			.'&file='.urlencode(self::relativePath($target).$fileInfo['filename'].'.'.strtolower($fileInfo['extension'] ?? ''));
	}

	/**
	 * list_of_documents(): the public link of a shared file, on the main URL of the instance.
	 */
	private function shareUrl(EcmFile $row): ?string
	{
		if ((string) $row->share === '') {
			return null;
		}
		$urlRoot = $this->config->urlRoot();
		$urlWithRoot = preg_replace('/'.preg_quote($urlRoot, '/').'$/i', '', trim($this->config->mainUrlRoot())).$urlRoot;

		return $urlWithRoot.'/document.php?hashp='.$row->share.($this->config->int('MAIN_FORCE_DOWNLOAD_IN_HTML_FORMFILE') !== 0 ? '&attachment=1' : '');
	}

	private static function merge(ActionResult $into, ActionResult $from): void
	{
		foreach ($from->messages as $message) {
			$into->add($message['type'], $message['text']);
		}
	}

	private function param(Request $request, string $name, string $filter): string
	{
		$value = $request->get($name, $filter);

		return is_string($value) ? $value : '';
	}
}
