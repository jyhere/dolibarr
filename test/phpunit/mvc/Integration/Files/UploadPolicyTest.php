<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Files;

use Dolibarr\Core\Files\Refusal;
use Dolibarr\Core\Files\UploadPolicy;
use Dolibarr\Tests\Integration\Fixture\OverridesLegacyConf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadPolicyTest extends TestCase
{
	use OverridesLegacyConf;

	private ?string $savedRestrictOsCommands = null;

	protected function setUp(): void
	{
		global $dolibarr_main_restrict_os_commands;
		$this->savedRestrictOsCommands = $dolibarr_main_restrict_os_commands ?? null;
	}

	protected function tearDown(): void
	{
		global $dolibarr_main_restrict_os_commands;
		$dolibarr_main_restrict_os_commands = $this->savedRestrictOsCommands;
		$this->restoreConf();
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function uploadedNames(): array
	{
		return [
			'accents and upper extension' => ['Été  Photo.JPG', ''],
			'html tags' => ['<b>x</b>.pdf', ''],
			'space and upper extension' => ['a b.TXT', ''],
			'mask applied' => ['facture.pdf', 'REF-__file__'],
			'mask already prefixed' => ['REF-facture.pdf', 'REF-__file__'],
		];
	}

	/**
	 * Reproduction of dol_add_file_process l.2161-2177 and l.2194-2197 with the same utility functions.
	 */
	#[DataProvider('uploadedNames')]
	public function testTargetNameMatchesLegacy(string $originalName, string $savingDocMask): void
	{
		$destfile = trim($originalName);
		$destfilewithoutext = preg_replace('/\.[^\.]+$/', '', $destfile);
		if ($savingDocMask && strpos($savingDocMask, $destfilewithoutext) !== 0) {
			$destfile = trim(preg_replace('/__file__/', $originalName, $savingDocMask));
		}
		$info = pathinfo($destfile);
		$expected = dol_string_nohtmltag(dol_sanitizeFileName($info['filename'].(($info['extension'] ?? '') != '' ? ('.'.strtolower($info['extension'])) : '')));

		self::assertSame($expected, (new UploadPolicy())->targetName($originalName, $savingDocMask));
	}

	public function testExecutableExtensionIsRefusedAndReservedCommandToo(): void
	{
		global $dolibarr_main_restrict_os_commands;
		$policy = new UploadPolicy();

		$refusal = $policy->refusalOfName('x.php');
		self::assertInstanceOf(Refusal::class, $refusal);
		self::assertSame('ErrorFilenameExtensionNotAllowed', $refusal->key);
		self::assertNull($policy->refusalOfName('x.pdf'));

		$dolibarr_main_restrict_os_commands = 'mysqldump, mysql';
		$refusal = $policy->refusalOfName('mysqldump');
		self::assertInstanceOf(Refusal::class, $refusal);
		self::assertSame('ErrorFilenameReserved', $refusal->key);

		$refusal = $policy->refusalOfName('.hidden');
		self::assertInstanceOf(Refusal::class, $refusal);
		self::assertSame('ErrorFilenameCantStartWithDot', $refusal->key);
	}

	public function testRefusalBeforeUpload(): void
	{
		$policy = new UploadPolicy();

		self::assertSame('ErrorFileSizeTooLarge', $policy->refusalBeforeUpload('a.pdf', 1, '')?->key);
		self::assertSame('ErrorFileSizeTooLarge', $policy->refusalBeforeUpload('a.pdf', 2, '')?->key);
		self::assertSame('ErrorFieldRequired', $policy->refusalBeforeUpload('a.pdf', 4, '')?->key);
		self::assertSame('ErrorWrongFileName', $policy->refusalBeforeUpload('a__x__.pdf', 0, '/tmp/php1')?->key);
		self::assertNull($policy->refusalBeforeUpload('a.pdf', 0, '/tmp/php1'));
	}

	public function testUploadErrorKey(): void
	{
		$policy = new UploadPolicy();

		self::assertNull($policy->uploadErrorKey(0));
		self::assertSame('ErrorFileSizeTooLarge', $policy->uploadErrorKey(UPLOAD_ERR_INI_SIZE));
		self::assertSame('ErrorFileSizeTooLarge', $policy->uploadErrorKey(UPLOAD_ERR_FORM_SIZE));
		self::assertSame('ErrorPartialFile', $policy->uploadErrorKey(UPLOAD_ERR_PARTIAL));
		self::assertSame('ErrorNoTmpDir', $policy->uploadErrorKey(UPLOAD_ERR_NO_TMP_DIR));
		self::assertSame('ErrorFailedToWriteInDir', $policy->uploadErrorKey(UPLOAD_ERR_CANT_WRITE));
		self::assertSame('ErrorUploadBlockedByAddon', $policy->uploadErrorKey(UPLOAD_ERR_EXTENSION));
		self::assertNull($policy->uploadErrorKey(UPLOAD_ERR_NO_FILE));
	}

	public function testNoexeFollowsTheConstant(): void
	{
		$policy = new UploadPolicy();
		$directory = DOL_DATA_ROOT.'/user/1';

		$this->setConf('MAIN_DOCUMENT_IS_OUTSIDE_WEBROOT_SO_NOEXE_NOT_REQUIRED', '');
		self::assertTrue($policy->needsNoexe('x.sh', $directory));
		self::assertFalse($policy->needsNoexe('x.pdf', $directory));

		$this->setConf('MAIN_DOCUMENT_IS_OUTSIDE_WEBROOT_SO_NOEXE_NOT_REQUIRED', 1);
		self::assertFalse($policy->needsNoexe('x.sh', $directory));
	}

	public function testNoexeInMediasDirectoryDependsOnDisableConstant(): void
	{
		global $conf;
		$policy = new UploadPolicy();
		$mediasDirectory = $conf->medias->multidir_output[$conf->entity].'/image';

		$this->setConf('MAIN_DOCUMENT_IS_OUTSIDE_WEBROOT_SO_NOEXE_NOT_REQUIRED', '');
		$this->setConf('MAIN_DOCUMENT_DISABLE_NOEXE_IN_MEDIAS_DIR', '');
		self::assertTrue($policy->needsNoexe('x.sh', $mediasDirectory));

		$this->setConf('MAIN_DOCUMENT_DISABLE_NOEXE_IN_MEDIAS_DIR', 1);
		self::assertFalse($policy->needsNoexe('x.sh', $mediasDirectory));
	}

	public function testRenameTarget(): void
	{
		global $dolibarr_main_restrict_os_commands;
		$policy = new UploadPolicy();
		$directory = DOL_DATA_ROOT.'/user/1';
		$this->setConf('MAIN_DOCUMENT_IS_OUTSIDE_WEBROOT_SO_NOEXE_NOT_REQUIRED', '');

		self::assertSame('Été ok.pdf', $policy->renameTarget('Été  ok.pdf', $directory));
		self::assertSame('a.sh.noexe', $policy->renameTarget('a.sh', $directory));

		$refusal = $policy->renameTarget('a__x__.pdf', $directory);
		self::assertInstanceOf(Refusal::class, $refusal);
		self::assertSame('ErrorWrongFileName', $refusal->key);

		$dolibarr_main_restrict_os_commands = 'mysqldump';
		$refusal = $policy->renameTarget('mysqldump', $directory);
		self::assertInstanceOf(Refusal::class, $refusal);
		self::assertSame('ErrorFilenameReserved', $refusal->key);

		$refusal = $policy->renameTarget('.htaccess', $directory);
		self::assertInstanceOf(Refusal::class, $refusal);
		self::assertSame('ErrorFilenameCantStartWithDot', $refusal->key);
	}

	public function testLinkUrl(): void
	{
		$policy = new UploadPolicy();

		self::assertSame('http://example.com/a', $policy->normalizeLinkUrl('example.com/a'));
		self::assertSame('https://x/y', $policy->normalizeLinkUrl('https://x/y'));
		self::assertSame('http://file://x', $policy->normalizeLinkUrl('file://x'));
		$this->setConf('MAIN_ALLOW_LINK_STARTING_WITH_FILE', 1);
		self::assertSame('file://x', $policy->normalizeLinkUrl('file://x'));

		$refusal = $policy->refusalOfLink('https://x/y.svg');
		self::assertInstanceOf(Refusal::class, $refusal);
		self::assertSame('ErrorSVGFilesNotAllowedAsLinksWithout', $refusal->key);
		self::assertSame(['MAIN_ALLOW_SVG_FILES_AS_EXTERNAL_LINKS'], $refusal->params);
		self::assertNull($policy->refusalOfLink('https://x/y.png'));

		$this->setConf('MAIN_ALLOW_SVG_FILES_AS_EXTERNAL_LINKS', 1);
		self::assertNull($policy->refusalOfLink('https://x/y.svg'));
	}

	public function testMaxFilesPerForm(): void
	{
		$policy = new UploadPolicy();

		$this->setConf('MAIN_SECURITY_MAX_ATTACHMENT_ON_FORMS', 0);
		unset($GLOBALS['conf']->global->MAIN_SECURITY_MAX_ATTACHMENT_ON_FORMS);
		self::assertSame(10, $policy->maxFilesPerForm());
		$this->setConf('MAIN_SECURITY_MAX_ATTACHMENT_ON_FORMS', 3);
		self::assertSame(3, $policy->maxFilesPerForm());
	}
}
