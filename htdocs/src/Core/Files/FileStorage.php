<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

use Dolibarr\Core\Http\UploadedFile;
use Dolibarr\Core\Legacy\LegacyHooks;

/**
 * Writes, deletes and renames attached files on disk, with the checks and the hooks of the legacy
 * dol_move_uploaded_file(), dol_delete_file() (exact name) and dol_move(). It never touches the files index.
 */
final class FileStorage
{
	public function __construct(
		private readonly LegacyHooks $hooks,
		private readonly UploadedFileMover $mover,
		private readonly UploadPolicy $policy,
	) {
	}

	/**
	 * Store an uploaded file as $targetName inside the guarded directory.
	 */
	public function storeUpload(UploadedFile $upload, PathGuard $guard, string $targetName, bool $overwrite, ?\CommonObject $pageObject): StoredFile|Refusal
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		try {
			$destination = $guard->inside($targetName);
		} catch (UnsafePathException) {
			\dol_syslog('FileStorage::storeUpload refused the unsafe name '.$targetName, LOG_WARNING);

			return new Refusal('ErrorFileNotUploaded');
		}

		$object = $pageObject;
		$action = '';
		$this->hooks->init(['fileslib']);
		$hookResult = $this->hooks->execute('moveUploadedFile', [
			'dest_file' => $destination,
			'src_file' => $upload->tmpName,
			'file_name' => $destination,
			'varfiles' => 'userfile',
			'allowoverwrite' => $overwrite,
		], $object, $action);

		if ($hookResult->code < 0) {
			$message = implode(',', $hookResult->errors);

			return new Refusal($message !== '' ? $message : 'ErrorReturnedBySomeHooks');
		}
		if ($hookResult->code > 0) {
			return new StoredFile($destination, $targetName, false);
		}

		$errorKey = $this->policy->uploadErrorKey($upload->error);
		if ($errorKey !== null) {
			return new Refusal($errorKey);
		}

		if (file_exists($upload->tmpName)) {
			$virusChecks = \dolCheckVirus($upload->tmpName, $destination);
			if (count($virusChecks) > 0) {
				\dol_syslog('FileStorage::storeUpload "'.$upload->tmpName.'" (target "'.$destination.'") refused by the antivirus: '.implode(',', $virusChecks), LOG_WARNING);
				foreach (array_keys($virusChecks) as $errorKeyOfCheck) {
					if (is_string($errorKeyOfCheck)) {
						return new Refusal($errorKeyOfCheck);
					}
				}

				return new Refusal('ErrorFileIsInfectedWithAVirus');
			}
		}

		$noexe = $this->policy->needsNoexe($destination, dirname($destination));
		$storedName = $noexe ? $targetName.'.noexe' : $targetName;
		$storedPath = $destination.($noexe ? '.noexe' : '');

		if (
			str_starts_with(basename($upload->tmpName), '.')
			|| str_contains($upload->tmpName, '..')
			|| preg_match('/[<>|]/', $upload->tmpName) === 1
		) {
			\dol_syslog('FileStorage::storeUpload refused to deliver '.$upload->tmpName, LOG_WARNING);

			return new Refusal('ErrorFileNotUploaded');
		}

		if (!is_writable(dirname(\dol_osencode($storedPath)))) {
			return new Refusal('ErrorDirNotWritable');
		}
		if (!$overwrite) {
			if (file_exists(\dol_osencode($storedPath))) {
				return new Refusal('ErrorFileAlreadyExists');
			}
		} elseif (is_dir(\dol_osencode($storedPath))) {
			return new Refusal('ErrorDirWithFileNameAlreadyExists');
		}

		if (!$this->mover->move(\dol_osencode($upload->tmpName), \dol_osencode($storedPath))) {
			\dol_syslog('FileStorage::storeUpload failed to move '.$upload->tmpName.' to '.$storedPath, LOG_ERR);

			return new Refusal('ErrorFileNotUploaded');
		}
		\dolChmod(\dol_osencode($storedPath));

		return new StoredFile($storedPath, $storedName, $noexe);
	}

	/**
	 * Delete one file by its exact path: the deleteFile hook first (a code other than 0 replaces the deletion),
	 * then unlink(), retried once after giving the parent directory the write permission.
	 */
	public function delete(string $absolutePath, ?\CommonObject $pageObject): bool
	{
		if (str_contains($absolutePath, '..') || preg_match('/[<>|]/', $absolutePath) === 1) {
			\dol_syslog('FileStorage::delete refused '.$absolutePath, LOG_WARNING);

			return false;
		}

		$object = $pageObject;
		$action = '';
		$this->hooks->init(['fileslib']);
		$hookResult = $this->hooks->execute('deleteFile', [
			'file' => $absolutePath,
			'disableglob' => 1,
			'nophperrors' => 0,
		], $object, $action);
		if ($hookResult->code !== 0) {
			return $hookResult->code > 0;
		}

		$osEncodedPath = \dol_osencode($absolutePath);
		$deleted = @unlink($osEncodedPath);
		$parentDirectory = dirname($osEncodedPath);
		if (!$deleted && file_exists($parentDirectory) && !(fileperms($parentDirectory) & 0200)) {
			\dolChmod($parentDirectory, decoct(fileperms($parentDirectory) | 0200));
			$deleted = @unlink($osEncodedPath);
		}

		return $deleted;
	}

	/**
	 * Rename a file, replacing the target (dol_move with overwriteifexists = 1 and testvirus = 0).
	 */
	public function rename(string $sourcePath, string $targetPath, ?\CommonObject $pageObject): bool
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		if (!is_file($sourcePath)) {
			return false;
		}
		$targetExisted = is_file($targetPath);

		$osEncodedSource = \dol_osencode($sourcePath);
		$osEncodedTarget = \dol_osencode($targetPath);
		if (count(\dolCheckOnFileName($osEncodedSource, $osEncodedTarget)) > 0) {
			\dol_syslog('FileStorage::rename canceled, the file name check failed', LOG_WARNING);

			return false;
		}

		global $dolibarr_main_restrict_os_commands;
		if (!empty($dolibarr_main_restrict_os_commands)) {
			$reservedCommands = array_map('trim', explode(',', $dolibarr_main_restrict_os_commands));
			if (in_array(basename($targetPath), $reservedCommands, true)) {
				\dol_syslog('FileStorage::rename canceled, '.basename($targetPath).' is a reserved command name', LOG_WARNING);

				return false;
			}
		}

		$renamed = @rename($osEncodedSource, $osEncodedTarget);
		if (!$renamed && $targetExisted) {
			// Replacing an existing file can fail on some filesystems: delete the target and try again
			$this->delete($targetPath, $pageObject);
			$renamed = @rename($osEncodedSource, $osEncodedTarget);
		}

		return $renamed;
	}
}
