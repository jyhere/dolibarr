<?php

declare(strict_types=1);

namespace Dolibarr\Module\Documents\Service;

use Dolibarr\Core\Config;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Files\Deletion;
use Dolibarr\Core\Files\EcmFileRepository;
use Dolibarr\Core\Files\ExternalLink;
use Dolibarr\Core\Files\ExternalLinkRepository;
use Dolibarr\Core\Files\FileIndex;
use Dolibarr\Core\Files\FileStorage;
use Dolibarr\Core\Files\FileTriggerException;
use Dolibarr\Core\Files\PathGuard;
use Dolibarr\Core\Files\Refusal;
use Dolibarr\Core\Files\StoredFile;
use Dolibarr\Core\Files\Thumbnails;
use Dolibarr\Core\Files\UnsafePathException;
use Dolibarr\Core\Files\UploadPolicy;
use Dolibarr\Core\Http\UploadedFile;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Translator;

/**
 * The actions of the documents tab of an object, as htdocs/core/actions_linkedfiles.inc.php runs them: upload
 * (with dol_add_file_process()), link, deletion of a file or a link, rename, sharing of a file, update of a link.
 * Each action returns its messages, with the keys and types of the legacy one, in an ActionResult.
 *
 * The caller checks that the user may write the documents (DocumentsTarget::$canWrite) before any action.
 */
final class AttachedFilesService
{
	private const WRONG_FILE_NAME = '/__.*__/';

	public function __construct(
		private readonly FileStorage $storage,
		private readonly FileIndex $index,
		private readonly Thumbnails $thumbnails,
		private readonly UploadPolicy $policy,
		private readonly LinkWriter $linkWriter,
		private readonly ExternalLinkRepository $links,
		private readonly EcmFileRepository $files,
		private readonly LegacyHooks $hooks,
		private readonly Translator $translator,
		private readonly Config $config
	) {
	}

	/**
	 * Upload (actions_linkedfiles.inc.php l.68-104, then dol_add_file_process() l.2132-2280): nothing without
	 * MAIN_UPLOAD_DOC; every file is checked before any is stored; a name starting with a dot stops the loop (the
	 * files already stored are reported), an extension not allowed or a reserved name stops the action.
	 *
	 * @param list<UploadedFile> $uploads
	 * @throws \LogicException     Without a logged user, or if a trigger object cannot be loaded
	 * @throws DatabaseException   When the index row of an overwritten file cannot be removed
	 * @throws \InvalidArgumentException
	 */
	public function upload(DocumentsTarget $target, array $uploads, bool $overwrite, string $savingDocMask): ActionResult
	{
		$result = $this->newResult();
		if (self::isEmpty($this->config->string('MAIN_UPLOAD_DOC')) || $uploads === []) {
			return $result;
		}

		$refused = false;
		foreach ($uploads as $upload) {
			$refusal = $this->policy->refusalBeforeUpload($upload->name, $upload->error, $upload->tmpName);
			if ($refusal !== null) {
				$refused = true;
				$result->add('errors', $this->message($refusal));
			}
			if ($upload->tmpName === '' && preg_match(self::WRONG_FILE_NAME, $upload->name) === 1) {
				// Legacy reports both the missing file and the wrong name
				$result->add('errors', $this->translator->trans('ErrorWrongFileName'));
			}
		}
		if ($refused) {
			return $result;
		}

		$maxFiles = $this->policy->maxFilesPerForm();
		if (count($uploads) > $maxFiles) {
			$result->add('errors', $this->translator->trans('ErrorTooMuchFileInForm', (string) $maxFiles));

			return $result;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
		if (dol_mkdir($target->directory) < 0) {
			$result->add('errors', $this->translator->transHtml('ErrorFailedToCreateDir', $target->directory));

			return $result;
		}
		$guard = new PathGuard($target->directory);

		$storedCount = 0;
		foreach ($uploads as $upload) {
			if (self::isEmpty($upload->name)) {
				continue;
			}
			$targetName = $this->policy->targetName($upload->name, $savingDocMask);
			$refusal = $this->policy->refusalOfName($targetName);
			if ($refusal !== null) {
				$result->add('errors', $this->message($refusal));
				if ($refusal->key === 'ErrorFilenameCantStartWithDot') {
					break;
				}

				return $result;
			}

			$stored = $this->storage->storeUpload($upload, $guard, $targetName, $overwrite, $target->legacyObject);
			if ($stored instanceof Refusal) {
				$result->add('errors', $this->message($stored));
				continue;
			}
			$this->thumbnails->createForUpload($stored->path);
			$this->indexUpload($target, $stored, $upload, $overwrite, $result);
			$storedCount++;
		}

		if ($storedCount > 0) {
			$result->add('mesgs', $this->translator->trans('FileTransferComplete'));
		}

		return $result;
	}

	/**
	 * Link (actions_linkedfiles.inc.php l.106-133, then dol_add_file_process() l.2291-2305), to the object of the
	 * target (ruling R3).
	 *
	 * @throws \LogicException Without a logged user, or if the trigger object cannot be loaded
	 */
	public function addLink(DocumentsTarget $target, string $rawUrl, string $label): ActionResult
	{
		$result = $this->newResult();
		if (self::isEmpty($this->config->string('MAIN_UPLOAD_DOC')) || self::isEmpty($rawUrl)) {
			return $result;
		}

		$url = $this->policy->normalizeLinkUrl($rawUrl);
		$refusal = $this->policy->refusalOfLink($url);
		if ($refusal !== null) {
			$result->add('errors', $this->message($refusal));

			return $result;
		}

		try {
			$this->linkWriter->create($target, $url, $label);
			$result->add('mesgs', $this->translator->trans('LinkComplete'));
		} catch (LinkWriteException) {
			// dol_add_file_process() shows neither the error of \Link nor the messages of its trigger
			$result->add('errors', $this->translator->trans('ErrorFileNotLinked'));
		}

		return $result;
	}

	/**
	 * Deletion of a file (actions_linkedfiles.inc.php l.137-183): only the base name of $urlFile is kept, inside the
	 * directory of the target. A failing ECMFILES_DELETE leaves the row but the file is gone: a warning, as
	 * dol_delete_file() l.1745-1749, and the deletion is reported. A deleteFile hook that takes the deletion over
	 * leaves the index alone (dol_delete_file() l.1693-1698 returns at once) and the deletion is reported.
	 *
	 * @throws \LogicException Without a logged user, or if the trigger object cannot be loaded
	 */
	public function deleteFile(DocumentsTarget $target, string $urlFile): ActionResult
	{
		$result = $this->newResult();
		$fileName = basename($urlFile);
		if (self::isEmpty($fileName)) {
			return $result;
		}

		try {
			$path = (new PathGuard($target->directory))->inside($fileName);
		} catch (UnsafePathException) {
			$result->add('errors', $this->translator->transHtml('ErrorFailToDeleteFile', $fileName));

			return $result;
		}

		$deletion = $this->storage->delete($path, $target->legacyObject);
		if ($deletion === Deletion::Failed) {
			$result->add('errors', $this->translator->transHtml('ErrorFailToDeleteFile', $fileName));

			return $result;
		}

		if ($deletion === Deletion::Deleted) {
			try {
				$this->index->removeFile($path, $target->indexEntity());
			} catch (FileTriggerException $e) {
				$result->addAll('warnings', '', $e->errors);
			} catch (DatabaseException $e) {
				$result->add('warnings', self::databaseError($e));
			}
		}
		$this->thumbnails->deleteFor($path, $target->legacyObject);
		$result->add('mesgs', $this->translator->transHtml('FileWasRemoved', $fileName));

		return $result;
	}

	/**
	 * Deletion of a link (actions_linkedfiles.inc.php l.184-205).
	 *
	 * @throws AccessDeniedException The link does not exist or belongs to another object (legacy accessforbidden())
	 * @throws \LogicException       Without a logged user, or if the trigger object cannot be loaded
	 * @throws \InvalidArgumentException
	 */
	public function deleteLink(DocumentsTarget $target, int $linkId): ActionResult
	{
		$result = $this->newResult();
		$link = $this->linkOf($target, $linkId) ?? throw new AccessDeniedException();

		try {
			$this->linkWriter->delete($link);
			$result->add('mesgs', $this->translator->transHtml('LinkRemoved', $link->label));
		} catch (LinkWriteException $e) {
			if ($e->errors !== []) {
				$result->addAll('errors', '', $e->errors);
			} else {
				$result->add('errors', $this->translator->transHtml('ErrorFailedToDeleteLink', $link->label));
			}
		}

		return $result;
	}

	/**
	 * Rename (actions_linkedfiles.inc.php l.246-353): the renameUploadedFile hook (context actionlinkedfiles) may
	 * take the action over; the index row follows the file (created for a file the index does not know) and the
	 * thumbnails are made again for the new name.
	 *
	 * When the index cannot follow, the file stays moved but dol_move() returns false (files.lib.php l.1321-1325):
	 * the rename is reported as failed, without thumbnails. Only a failing creation of the row (unindexed source)
	 * shows its errors as warnings first (l.1305-1307); a failing update of the row shows nothing more.
	 *
	 * @throws \LogicException   Without a logged user, or if a trigger object cannot be loaded
	 * @throws DatabaseException When the index row of the source cannot be read
	 * @throws \InvalidArgumentException
	 */
	public function rename(DocumentsTarget $target, string $from, string $to): ActionResult
	{
		$result = $this->newResult();
		$fromName = dol_sanitizeFileName($from, '_', 0);
		$requestedName = dol_string_nohtmltag(dol_sanitizeFileName($to, '_', 0));

		$renamed = $this->policy->renameTarget($to, $target->directory);
		$dotRefusal = null;
		if ($renamed instanceof Refusal) {
			if ($renamed->key !== 'ErrorFilenameCantStartWithDot') {
				$result->add('errors', $this->message($renamed));

				return $result;
			}
			// Legacy runs the hook before refusing a name starting with a dot
			$dotRefusal = $renamed;
			$toName = (string) ($renamed->params[0] ?? $requestedName);
		} else {
			$toName = $renamed;
		}
		if ($fromName === $requestedName || self::isEmpty($fromName) || self::isEmpty($toName)) {
			return $result;
		}

		$this->hooks->init(['actionlinkedfiles']);
		$object = $target->legacyObject;
		$action = '';
		$hookResult = $this->hooks->execute('renameUploadedFile', [
			'filenamefrom' => $fromName,
			'filenameto' => $toName,
			'upload_dir' => $target->directory,
		], $object, $action);
		if ($hookResult->code !== 0) {
			return $result;
		}
		if ($dotRefusal !== null) {
			$result->add('errors', $this->message($dotRefusal));

			return $result;
		}

		$failure = $this->translator->transHtml('ErrorFailToRenameFile', $fromName, $toName);
		try {
			$guard = new PathGuard($target->directory);
			$sourcePath = $guard->inside($fromName);
			$targetPath = $guard->inside($toName);
		} catch (UnsafePathException) {
			$result->add('errors', $failure);

			return $result;
		}
		if (file_exists($targetPath)) {
			$result->add('errors', $this->translator->transHtml('ErrorDestinationAlreadyExists', $toName));

			return $result;
		}
		$sourceIndexed = $this->files->findByPath(
			$target->indexEntity(),
			$target->relativeDirectory(),
			(string) preg_replace('/\.noexe$/', '', $fromName)
		) !== null;
		if (!$this->storage->rename($sourcePath, $targetPath, $target->legacyObject)) {
			$result->add('errors', $failure);

			return $result;
		}

		$indexErrors = null;
		try {
			$this->index->rename($sourcePath, $targetPath, $target->indexEntity());
		} catch (FileTriggerException $e) {
			$indexErrors = $e->errors;
		} catch (DatabaseException $e) {
			$indexErrors = [self::databaseError($e)];
		}
		if ($indexErrors !== null) {
			if (!$sourceIndexed) {
				$result->addAll('warnings', '', $indexErrors);
			}
			$result->add('errors', $failure);

			return $result;
		}
		// No thumbnails for the medias (website module), nor for an object not saved
		if ($target->modulePart !== 'medias' && (int) $target->legacyObject->id > 0) {
			$this->thumbnails->regenerate($target->legacyObject, $sourcePath, $targetPath);
		}
		$result->add('mesgs', $this->translator->trans('FileRenamed'));

		return $result;
	}

	/**
	 * Sharing of an indexed file (actions_linkedfiles.inc.php l.355-374): nothing for a row that does not exist.
	 *
	 * The legacy fetches the row by its id without any check (EcmFiles::fetch()). Here the row must be one of the
	 * directory of the target (no row of another object: IDOR) and of one of its entities: the one its rows are
	 * written with (indexEntity()) or the one list_of_documents() lists and indexes unknown files with
	 * (listingEntity()). They differ for an object of the entity 0 (an admin user): the rename form then posts the
	 * id of a row of the current entity.
	 *
	 * @throws AccessDeniedException The row is the one of a file of another directory or entity
	 * @throws \LogicException       Without a logged user, or if the trigger object cannot be loaded
	 * @throws DatabaseException
	 * @throws \InvalidArgumentException
	 */
	public function share(DocumentsTarget $target, int $ecmFileId, bool $enabled): ActionResult
	{
		$result = $this->newResult();
		$file = $ecmFileId > 0 ? $this->files->find($ecmFileId) : null;
		if ($file === null) {
			return $result;
		}
		if ($file->filepath !== $target->relativeDirectory() || !in_array($file->entity, [$target->indexEntity(), $target->listingEntity()], true)) {
			throw new AccessDeniedException();
		}

		try {
			$this->index->setShared($file, $enabled);
		} catch (FileTriggerException $e) {
			$result->addAll('warnings', '', $e->errors);
		} catch (DatabaseException $e) {
			$result->add('warnings', self::databaseError($e));
		}

		return $result;
	}

	/**
	 * Update of a link (actions_linkedfiles.inc.php l.206-245): nothing without a URL or for a link that does not
	 * exist; no message on success.
	 *
	 * @throws AccessDeniedException The link belongs to another object
	 * @throws \LogicException       Without a logged user, or if the trigger object cannot be loaded
	 * @throws \InvalidArgumentException
	 */
	public function updateLink(DocumentsTarget $target, int $linkId, string $url, string $label, bool $share): ActionResult
	{
		$result = $this->newResult();
		if (self::isEmpty($url)) {
			return $result;
		}
		$link = $this->linkOf($target, $linkId);
		if ($link === null) {
			if ($this->linkExists($linkId)) {
				throw new AccessDeniedException();
			}

			return $result;
		}

		$normalizedUrl = $this->policy->normalizeLinkUrl($url);
		try {
			$this->linkWriter->update($link, $normalizedUrl, $label, $share);
		} catch (LinkWriteException $e) {
			$result->addAll(
				'errors',
				$this->translator->transHtml('ErrorFailedToUpdateLink', $this->linkWriter->cleanedLabel($normalizedUrl, $label)),
				$e->errors
			);
		}

		return $result;
	}

	/**
	 * The index row of an uploaded file (dol_add_file_process() l.2240-2268): the row of an overwritten file is
	 * removed first (its stored name, ".noexe" included, as the legacy passes it); a failure to remove it is logged and
	 * ignored; a failure to index is a warning, not shown when overwriting.
	 *
	 * @throws \LogicException
	 * @throws DatabaseException
	 * @throws \InvalidArgumentException
	 */
	private function indexUpload(DocumentsTarget $target, StoredFile $stored, UploadedFile $upload, bool $overwrite, ActionResult $result): void
	{
		$directory = $target->directory;
		if ($overwrite) {
			try {
				$this->index->removeIndexRow($directory, $stored->storedName, $target->indexEntity());
			} catch (DatabaseException $e) {
				// The legacy ignores the result of deleteFilesIntoDatabaseIndex(): the file is already overwritten
				\dol_syslog('AttachedFilesService::indexUpload failed to remove the index row of '.$stored->storedName.': '.$e->engineMessage(), LOG_WARNING);
			}
		}

		try {
			$this->index->add($directory, $stored->storedName, $upload->name, $target->legacyObject, false);
		} catch (FileTriggerException | DatabaseException) {
			if (!$overwrite) {
				// The legacy shows the key itself, untranslated
				$result->add('warnings', 'WarningFailedToAddFileIntoDatabaseIndex');
			}
		}
	}

	/**
	 * The link when it belongs to the object of the target, in the current entity (\Link::fetch()).
	 *
	 * @throws \InvalidArgumentException
	 */
	private function linkOf(DocumentsTarget $target, int $linkId): ?ExternalLink
	{
		return $this->links->findForObject($linkId, (string) $target->legacyObject->element, (int) $target->legacyObject->id, $this->config->entity());
	}

	private function linkExists(int $linkId): bool
	{
		$link = $linkId > 0 ? $this->links->find($linkId) : null;
		$currentEntity = $this->config->entity();

		return $link !== null && ($currentEntity === 0 || $link->entity === $currentEntity);
	}

	/**
	 * A result, after loading the translation domains the legacy actions load.
	 */
	private function newResult(): ActionResult
	{
		foreach (['errors', 'link', 'other'] as $domain) {
			$this->translator->load($domain);
		}

		return new ActionResult();
	}

	/**
	 * The text of a refusal. Its key may be the literal message of a hook: an unknown key is returned as is, like
	 * $langs->trans() does. The parameter of ErrorFieldRequired is the key of the field label.
	 */
	private function message(Refusal $refusal): string
	{
		if ($refusal->key === 'ErrorFieldRequired') {
			return $this->translator->trans('ErrorFieldRequired', ...array_map(
				fn (string $fieldKey): string => $this->translator->transnoentities($fieldKey),
				array_values($refusal->params)
			));
		}

		return $this->translator->transHtml($refusal->key, ...array_values($refusal->params));
	}

	/**
	 * The message \EcmFiles keeps for a database error ('Error '.$db->lasterror()).
	 */
	private static function databaseError(DatabaseException $exception): string
	{
		return 'Error '.$exception->engineMessage();
	}

	/**
	 * PHP empty() of a text.
	 */
	private static function isEmpty(string $value): bool
	{
		return $value === '' || $value === '0';
	}
}
