<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Files;

use Dolibarr\Core\Files\Deletion;
use Dolibarr\Core\Files\FileStorage;
use Dolibarr\Core\Files\PathGuard;
use Dolibarr\Core\Files\Refusal;
use Dolibarr\Core\Files\StoredFile;
use Dolibarr\Core\Files\Thumbnails;
use Dolibarr\Core\Files\UploadPolicy;
use Dolibarr\Core\Http\UploadedFile;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Tests\Integration\Fixture\RenamingUploadedFileMover;
use Dolibarr\Tests\Integration\Fixture\ScriptedHooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileStorageTest extends TestCase
{
	private string $directory;

	/** @var list<string> */
	private array $temporaryFiles = [];

	protected function setUp(): void
	{
		$this->directory = DOL_DATA_ROOT.'/filestorage-test-'.bin2hex(random_bytes(4));
		mkdir($this->directory, 0777, true);
	}

	protected function tearDown(): void
	{
		foreach ($this->temporaryFiles as $temporaryFile) {
			if (file_exists($temporaryFile)) {
				unlink($temporaryFile);
			}
		}
		$this->removeTree($this->directory);
	}

	private function removeTree(string $directory): void
	{
		foreach (glob($directory.'/{*,.[!.]*}', GLOB_BRACE) ?: [] as $entry) {
			if (is_dir($entry) && !is_link($entry)) {
				$this->removeTree($entry);
			} else {
				unlink($entry);
			}
		}
		rmdir($directory);
	}

	private function storage(?ScriptedHooks $scripted = null): FileStorage
	{
		$hookManager = new \HookManager($GLOBALS['db']);
		if ($scripted !== null) {
			ScriptedHooks::register($hookManager, 'fileslib', $scripted);
			$hookManager->contextarray = ['fileslib'];
		}

		return new FileStorage(new LegacyHooks($hookManager), new RenamingUploadedFileMover(), new UploadPolicy());
	}

	private function upload(string $name, string $content = 'content', int $error = 0): UploadedFile
	{
		$temporaryPath = sys_get_temp_dir().'/upload-'.bin2hex(random_bytes(4));
		file_put_contents($temporaryPath, $content);
		$this->temporaryFiles[] = $temporaryPath;

		return new UploadedFile($name, $temporaryPath, 'text/plain', strlen($content), $error);
	}

	public function testStoreUploadMovesAndChmods(): void
	{
		global $conf;
		$previousUmask = $conf->global->MAIN_UMASK ?? null;
		$conf->global->MAIN_UMASK = '0640';
		try {
			$upload = $this->upload('report.txt', 'hello');
			$stored = $this->storage()->storeUpload($upload, new PathGuard($this->directory), 'report.txt', false, null);
		} finally {
			if ($previousUmask === null) {
				unset($conf->global->MAIN_UMASK);
			} else {
				$conf->global->MAIN_UMASK = $previousUmask;
			}
		}

		self::assertInstanceOf(StoredFile::class, $stored);
		self::assertSame($this->directory.'/report.txt', $stored->path);
		self::assertSame('report.txt', $stored->storedName);
		self::assertFalse($stored->noexe);
		self::assertSame('hello', file_get_contents($stored->path));
		self::assertSame('0640', substr(sprintf('%o', fileperms($stored->path)), -4));
		self::assertFileDoesNotExist($upload->tmpName);
	}

	public function testStoreUploadRefusesAnExistingFileWithoutOverwrite(): void
	{
		file_put_contents($this->directory.'/report.txt', 'original');

		$result = $this->storage()->storeUpload($this->upload('report.txt', 'new'), new PathGuard($this->directory), 'report.txt', false, null);

		self::assertEquals(new Refusal('ErrorFileAlreadyExists'), $result);
		self::assertSame('original', file_get_contents($this->directory.'/report.txt'));
	}

	public function testStoreUploadOverwritesWhenAllowed(): void
	{
		file_put_contents($this->directory.'/report.txt', 'original');

		$result = $this->storage()->storeUpload($this->upload('report.txt', 'new'), new PathGuard($this->directory), 'report.txt', true, null);

		self::assertInstanceOf(StoredFile::class, $result);
		self::assertSame('new', file_get_contents($this->directory.'/report.txt'));
	}

	public function testStoreUploadRefusesADirectoryWithTheFileName(): void
	{
		mkdir($this->directory.'/report.txt');

		$result = $this->storage()->storeUpload($this->upload('report.txt'), new PathGuard($this->directory), 'report.txt', true, null);

		self::assertEquals(new Refusal('ErrorDirWithFileNameAlreadyExists'), $result);
	}

	public function testStoreUploadAppendsNoexe(): void
	{
		$stored = $this->storage()->storeUpload($this->upload('x.sh'), new PathGuard($this->directory), 'x.sh', false, null);

		self::assertInstanceOf(StoredFile::class, $stored);
		self::assertSame('x.sh.noexe', $stored->storedName);
		self::assertTrue($stored->noexe);
		self::assertFileExists($this->directory.'/x.sh.noexe');
		self::assertFileDoesNotExist($this->directory.'/x.sh');
	}

	public function testHookMoveUploadedFileCanRefuse(): void
	{
		$scripted = new ScriptedHooks(['moveUploadedFile' => ['code' => -1, 'errors' => ['Blocked by policy']]]);

		$result = $this->storage($scripted)->storeUpload($this->upload('report.txt'), new PathGuard($this->directory), 'report.txt', false, null);

		self::assertEquals(new Refusal('Blocked by policy'), $result);
		self::assertSame([], glob($this->directory.'/*'));
		self::assertSame($this->directory.'/report.txt', $scripted->parameters['moveUploadedFile']['dest_file']);
		self::assertSame('userfile', $scripted->parameters['moveUploadedFile']['varfiles']);
	}

	public function testHookWithoutErrorTextRefusesWithTheGenericKey(): void
	{
		$scripted = new ScriptedHooks(['moveUploadedFile' => ['code' => -1]]);

		$result = $this->storage($scripted)->storeUpload($this->upload('report.txt'), new PathGuard($this->directory), 'report.txt', false, null);

		self::assertEquals(new Refusal('ErrorReturnedBySomeHooks'), $result);
	}

	public function testHookMoveUploadedFileCanTakeOver(): void
	{
		$scripted = new ScriptedHooks(['moveUploadedFile' => ['code' => 1]]);

		$result = $this->storage($scripted)->storeUpload($this->upload('report.txt'), new PathGuard($this->directory), 'report.txt', false, null);

		self::assertInstanceOf(StoredFile::class, $result);
		self::assertSame('report.txt', $result->storedName);
		self::assertSame([], glob($this->directory.'/*'));
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function uploadErrors(): array
	{
		return [
			'ini size' => [1, 'ErrorFileSizeTooLarge'],
			'form size' => [2, 'ErrorFileSizeTooLarge'],
			'partial' => [3, 'ErrorPartialFile'],
		];
	}

	#[DataProvider('uploadErrors')]
	public function testUploadErrorCodesMapToLegacyKeys(int $phpError, string $key): void
	{
		$result = $this->storage()->storeUpload($this->upload('report.txt', 'x', $phpError), new PathGuard($this->directory), 'report.txt', false, null);

		self::assertEquals(new Refusal($key), $result);
		self::assertSame([], glob($this->directory.'/*'));
	}

	public function testStoreUploadRefusesAnUnwritableDirectory(): void
	{
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			self::markTestSkipped('root can write everywhere');
		}
		chmod($this->directory, 0500);
		try {
			$result = $this->storage()->storeUpload($this->upload('report.txt'), new PathGuard($this->directory), 'report.txt', false, null);
		} finally {
			chmod($this->directory, 0700);
		}

		self::assertEquals(new Refusal('ErrorDirNotWritable'), $result);
	}

	public function testStoreUploadRefusesAnUnsafeTargetName(): void
	{
		$result = $this->storage()->storeUpload($this->upload('x'), new PathGuard($this->directory), '../x', false, null);

		self::assertEquals(new Refusal('ErrorFileNotUploaded'), $result);
	}

	public function testStoreUploadReportsAFailedMove(): void
	{
		$upload = new UploadedFile('report.txt', $this->directory.'/missing-source', 'text/plain', 1, 0);

		$result = @$this->storage()->storeUpload($upload, new PathGuard($this->directory), 'report.txt', false, null);

		self::assertEquals(new Refusal('ErrorFileNotUploaded'), $result);
	}

	public function testDeleteRemovesTheFile(): void
	{
		file_put_contents($this->directory.'/a.txt', 'a');

		self::assertSame(Deletion::Deleted, $this->storage()->delete($this->directory.'/a.txt', null));
		self::assertFileDoesNotExist($this->directory.'/a.txt');
		self::assertSame(Deletion::Failed, $this->storage()->delete($this->directory.'/a.txt', null));
	}

	public function testDeleteRefusesTraversal(): void
	{
		self::assertSame(Deletion::Failed, $this->storage()->delete($this->directory.'/../x.txt', null));
	}

	public function testDeleteRunsTheDeleteFileHook(): void
	{
		file_put_contents($this->directory.'/a.txt', 'a');
		$scripted = new ScriptedHooks(['deleteFile' => ['code' => 1]]);

		$deletion = $this->storage($scripted)->delete($this->directory.'/a.txt', null);
		self::assertSame(Deletion::TakenOverByHook, $deletion);
		self::assertTrue($deletion->succeeded());
		self::assertFileExists($this->directory.'/a.txt');
		self::assertSame(1, $scripted->parameters['deleteFile']['disableglob']);
		self::assertSame(0, $scripted->parameters['deleteFile']['nophperrors']);
		self::assertSame($this->directory.'/a.txt', $scripted->parameters['deleteFile']['file']);
	}

	public function testDeleteFailsWhenTheHookFails(): void
	{
		file_put_contents($this->directory.'/a.txt', 'a');
		$scripted = new ScriptedHooks(['deleteFile' => ['code' => -1]]);

		self::assertSame(Deletion::Failed, $this->storage($scripted)->delete($this->directory.'/a.txt', null));
		self::assertFileExists($this->directory.'/a.txt');
	}

	public function testRenameMovesAndReplacesTheTarget(): void
	{
		file_put_contents($this->directory.'/a.txt', 'a');
		file_put_contents($this->directory.'/b.txt', 'b');

		self::assertTrue($this->storage()->rename($this->directory.'/a.txt', $this->directory.'/b.txt', null));
		self::assertFileDoesNotExist($this->directory.'/a.txt');
		self::assertSame('a', file_get_contents($this->directory.'/b.txt'));
	}

	public function testRenameFailsWithoutSource(): void
	{
		self::assertFalse($this->storage()->rename($this->directory.'/none.txt', $this->directory.'/b.txt', null));
	}

	public function testRenameRefusesAReservedCommandName(): void
	{
		global $dolibarr_main_restrict_os_commands;
		$previous = $dolibarr_main_restrict_os_commands;
		$dolibarr_main_restrict_os_commands = 'git, ls';
		file_put_contents($this->directory.'/a.txt', 'a');
		try {
			$result = $this->storage()->rename($this->directory.'/a.txt', $this->directory.'/ls', null);
		} finally {
			$dolibarr_main_restrict_os_commands = $previous;
		}

		self::assertFalse($result);
		self::assertFileExists($this->directory.'/a.txt');
		self::assertFileDoesNotExist($this->directory.'/ls');
	}

	private function createPng(string $path): void
	{
		$image = imagecreatetruecolor(600, 400);
		self::assertNotFalse($image);
		imagepng($image, $path);
	}

	public function testThumbnailsOfAPng(): void
	{
		$path = $this->directory.'/x.png';
		$this->createPng($path);
		$storage = $this->storage();
		$thumbnails = new Thumbnails($storage);

		$thumbnails->createForUpload($path);

		self::assertFileExists($this->directory.'/thumbs/x_small.png');
		self::assertFileExists($this->directory.'/thumbs/x_mini.png');

		$thumbnails->deleteFor($path, null);

		self::assertFileDoesNotExist($this->directory.'/thumbs/x_small.png');
		self::assertFileDoesNotExist($this->directory.'/thumbs/x_mini.png');
	}

	public function testNoThumbnailForANonImage(): void
	{
		$path = $this->directory.'/x.txt';
		file_put_contents($path, 'text');

		(new Thumbnails($this->storage()))->createForUpload($path);

		self::assertDirectoryDoesNotExist($this->directory.'/thumbs');
	}

	public function testThumbnailsAreNotWrittenThroughASymlinkedThumbsDirectory(): void
	{
		$outside = sys_get_temp_dir().'/outside-'.bin2hex(random_bytes(4));
		mkdir($outside);
		symlink($outside, $this->directory.'/thumbs');
		$path = $this->directory.'/x.png';
		$this->createPng($path);

		(new Thumbnails($this->storage()))->createForUpload($path);

		try {
			self::assertSame([], glob($outside.'/*'));
		} finally {
			rmdir($outside);
		}
	}
}
