<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Http;

use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

final class RequestUploadedFilesTest extends TestCase
{
	public function testSingleAndMultipleUploads(): void
	{
		$single = new Request('POST', '/x', [], [], [], [], '', [
			'userfile' => ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/php1', 'error' => 0, 'size' => 3],
		]);
		self::assertEquals([new UploadedFile('a.txt', '/tmp/php1', 'text/plain', 3, 0)], $single->uploadedFiles('userfile'));

		$multiple = new Request('POST', '/x', [], [], [], [], '', [
			'userfile' => [
				'name' => ['a.txt', 'b.png'],
				'type' => ['text/plain', 'image/png'],
				'tmp_name' => ['/tmp/php1', '/tmp/php2'],
				'error' => [0, 4],
				'size' => [3, 0],
			],
		]);
		self::assertEquals(
			[new UploadedFile('a.txt', '/tmp/php1', 'text/plain', 3, 0), new UploadedFile('b.png', '/tmp/php2', 'image/png', 0, 4)],
			$multiple->uploadedFiles('userfile')
		);
	}

	public function testNoFilesGivesAnEmptyList(): void
	{
		self::assertSame([], (new Request('POST', '/x', [], [], [], []))->uploadedFiles('userfile'));
	}
}
