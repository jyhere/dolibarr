<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

/**
 * Rules of the upload, rename and link actions on attached files, reproduced from the legacy
 * (dol_add_file_process, dol_move_uploaded_file, actions_linkedfiles.inc.php) with the same utility functions.
 * Configuration is read through the legacy globals.
 */
final class UploadPolicy
{
	/**
	 * Final name of an uploaded file (sanitized, lowercase extension, no HTML).
	 */
	public function targetName(string $originalName, string $savingDocMask): string
	{
		$destfile = trim($originalName);
		$nameWithoutExtension = (string) preg_replace('/\.[^\.]+$/', '', $destfile);
		if ($savingDocMask !== '' && strpos($savingDocMask, $nameWithoutExtension) !== 0) {
			$destfile = trim((string) preg_replace('/__file__/', $originalName, $savingDocMask));
		}

		$info = pathinfo($destfile);
		$extension = $info['extension'] ?? '';
		$name = dol_sanitizeFileName($info['filename'].($extension !== '' ? '.'.strtolower($extension) : ''));

		return dol_string_nohtmltag($name);
	}

	public function refusalBeforeUpload(string $originalName, int $phpError, string $tmpName): ?Refusal
	{
		if ($tmpName === '') {
			if ($phpError === UPLOAD_ERR_INI_SIZE || $phpError === UPLOAD_ERR_FORM_SIZE) {
				return new Refusal('ErrorFileSizeTooLarge');
			}

			return new Refusal('ErrorFieldRequired', ['File']);
		}
		if (preg_match('/__.*__/', $originalName) === 1) {
			return new Refusal('ErrorWrongFileName');
		}

		return null;
	}

	public function refusalOfName(string $targetName): ?Refusal
	{
		if (str_starts_with($targetName, '.')) {
			return new Refusal('ErrorFilenameCantStartWithDot', [$targetName]);
		}

		$defaultExtensions = implode(',', getExecutableContent());
		$restriction = getDolGlobalString('MAIN_FILE_EXTENSION_UPLOAD_RESTRICTION', $defaultExtensions);
		if ($restriction !== '') {
			foreach (explode(',', $restriction) as $extension) {
				if (preg_match('/\.'.preg_quote(trim($extension), '/').'$/i', $targetName) === 1) {
					return new Refusal('ErrorFilenameExtensionNotAllowed', [$targetName]);
				}
			}
		}

		if ($this->isReservedCommand($targetName)) {
			return new Refusal('ErrorFilenameReserved', [$targetName]);
		}

		return null;
	}

	/**
	 * Translation key of a PHP upload error, null when there is none the legacy reports.
	 */
	public function uploadErrorKey(int $phpError): ?string
	{
		return match ($phpError) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ErrorFileSizeTooLarge',
			UPLOAD_ERR_PARTIAL => 'ErrorPartialFile',
			UPLOAD_ERR_NO_TMP_DIR => 'ErrorNoTmpDir',
			UPLOAD_ERR_CANT_WRITE => 'ErrorFailedToWriteInDir',
			UPLOAD_ERR_EXTENSION => 'ErrorUploadBlockedByAddon',
			default => null,
		};
	}

	/**
	 * Whether ".noexe" is appended to an uploaded file (dol_move_uploaded_file): executable content outside the
	 * medias directory, or inside it when MAIN_DOCUMENT_DISABLE_NOEXE_IN_MEDIAS_DIR is not set.
	 */
	public function needsNoexe(string $targetName, string $directory): bool
	{
		if (!$this->isExecutableOnWeb($targetName)) {
			return false;
		}

		return !$this->isInMediasDirectory($directory) || !getDolGlobalInt('MAIN_DOCUMENT_DISABLE_NOEXE_IN_MEDIAS_DIR');
	}

	/**
	 * Name a file is renamed to, or the refusal. Like the legacy rename, ".noexe" is never added in the medias
	 * directory, whatever MAIN_DOCUMENT_DISABLE_NOEXE_IN_MEDIAS_DIR says.
	 */
	public function renameTarget(string $requested, string $directory): string|Refusal
	{
		$name = dol_string_nohtmltag(dol_sanitizeFileName($requested, '_', 0));
		if (preg_match('/__.*__/', $name) === 1) {
			return new Refusal('ErrorWrongFileName');
		}
		if ($this->isReservedCommand(basename($name))) {
			return new Refusal('ErrorFilenameReserved', [basename($name)]);
		}

		if ($this->isExecutableOnWeb($name) && !$this->isInMediasDirectory($directory)) {
			$name .= '.noexe';
		}
		if (str_starts_with($name, '.')) {
			return new Refusal('ErrorFilenameCantStartWithDot', [$name]);
		}

		return $name;
	}

	public function normalizeLinkUrl(string $url): string
	{
		if (
			substr($url, 0, 7) !== 'http://'
			&& substr($url, 0, 8) !== 'https://'
			&& substr($url, 0, 7) !== 'davs://'
			&& (substr($url, 0, 7) !== 'file://' || !getDolGlobalString('MAIN_ALLOW_LINK_STARTING_WITH_FILE'))
		) {
			return 'http://'.$url;
		}

		return $url;
	}

	public function refusalOfLink(string $url): ?Refusal
	{
		if (!getDolGlobalString('MAIN_ALLOW_SVG_FILES_AS_EXTERNAL_LINKS')) {
			$path = parse_url($url, PHP_URL_PATH);
			if (!empty($path) && preg_match('/\.svg$/i', $path) === 1) {
				return new Refusal('ErrorSVGFilesNotAllowedAsLinksWithout', ['MAIN_ALLOW_SVG_FILES_AS_EXTERNAL_LINKS']);
			}
		}

		return null;
	}

	public function maxFilesPerForm(): int
	{
		return getDolGlobalInt('MAIN_SECURITY_MAX_ATTACHMENT_ON_FORMS', 10);
	}

	private function isExecutableOnWeb(string $name): bool
	{
		return isAFileWithExecutableContent($name) && !getDolGlobalString('MAIN_DOCUMENT_IS_OUTSIDE_WEBROOT_SO_NOEXE_NOT_REQUIRED');
	}

	private function isInMediasDirectory(string $directory): bool
	{
		global $conf;

		$mediasDirectory = $conf->medias->multidir_output[$conf->entity];
		if (!preg_match('/\/$/', $mediasDirectory)) {
			$mediasDirectory .= '/';
		}

		return strpos($directory.'/', $mediasDirectory) === 0;
	}

	private function isReservedCommand(string $name): bool
	{
		global $dolibarr_main_restrict_os_commands;

		if (empty($dolibarr_main_restrict_os_commands)) {
			return false;
		}

		return in_array($name, array_map('trim', explode(',', $dolibarr_main_restrict_os_commands)), true);
	}
}
