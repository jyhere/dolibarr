<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm;

use Dolibarr\Core\Orm\EntityMapping;
use Dolibarr\Core\Orm\MappingCache;
use Dolibarr\Core\Orm\MappingReader;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Tests\Unit\Orm\Fixture\Article;
use Dolibarr\Tests\Unit\Orm\Fixture\Author;
use PHPUnit\Framework\TestCase;

final class MappingCacheTest extends TestCase
{
	private string $file;

	protected function setUp(): void
	{
		$this->file = sys_get_temp_dir().'/orm_mappings_'.uniqid().'/cache/orm_mappings.php';
	}

	protected function tearDown(): void
	{
		@unlink($this->file);
		@rmdir(dirname($this->file));
		@rmdir(dirname($this->file, 2));
	}

	public function testRoundTrip(): void
	{
		$reader = new MappingReader();
		$article = $reader->read(Article::class);
		$author = $reader->read(Author::class);

		$cache = new MappingCache($this->file);
		self::assertNull($cache->load(Article::class, 's1'));
		$cache->store($article, 's1');
		$cache->store($author, 's2');

		$reloaded = new MappingCache($this->file);
		self::assertEquals($article, $reloaded->load(Article::class, 's1'));
		self::assertEquals($author, $reloaded->load(Author::class, 's2'));
		self::assertEquals($article, EntityMapping::fromArray($article->toArray()));
	}

	public function testStaleStampIsIgnored(): void
	{
		$cache = new MappingCache($this->file);
		$cache->store((new MappingReader())->read(Article::class), 's1');

		self::assertNull((new MappingCache($this->file))->load(Article::class, 's2'));
		self::assertNull((new MappingCache($this->file))->load(Author::class, 's1'));
	}

	public function testMalformedCacheIsIgnored(): void
	{
		mkdir(dirname($this->file), 0775, true);
		foreach (['<?php return 42;', '<?php return ["entries" => 1];', '<?php return ["entries" => ["'.addslashes(Article::class).'" => ["stamp" => "s1", "mapping" => 5]]];'] as $content) {
			file_put_contents($this->file, $content);
			self::assertNull((new MappingCache($this->file))->load(Article::class, 's1'));
		}
	}

	public function testStoreThrowsWhenCacheDirectoryCannotBeCreated(): void
	{
		$notADir = tempnam(sys_get_temp_dir(), 'orm-file');
		self::assertIsString($notADir);
		try {
			$this->expectException(\RuntimeException::class);
			(new MappingCache($notADir.'/cache/orm_mappings.php'))->store((new MappingReader())->read(Author::class), 's');
		} finally {
			unlink($notADir);
		}
	}

	public function testRegistryUsesTheFileCacheWithAFreshStamp(): void
	{
		$registry = new MappingRegistry(new MappingReader(), new MappingCache($this->file), '23.0.0');
		$mapping = $registry->get(Article::class);
		self::assertSame($mapping, $registry->get(Article::class));
		self::assertEquals($mapping, (new MappingCache($this->file))->load(Article::class, $registry->stamp(Article::class)));

		$tampered = $mapping->toArray();
		$tampered['table'] = 'from_cache';
		(new MappingCache($this->file))->store(EntityMapping::fromArray($tampered), $registry->stamp(Article::class));
		$cached = new MappingRegistry(new MappingReader(), new MappingCache($this->file), '23.0.0');
		self::assertSame('from_cache', $cached->get(Article::class)->table);

		$upgraded = new MappingRegistry(new MappingReader(), new MappingCache($this->file), '24.0.0');
		self::assertNotSame($registry->stamp(Article::class), $upgraded->stamp(Article::class));
		self::assertSame('article', $upgraded->get(Article::class)->table);
	}

	public function testStampFollowsTheClassFile(): void
	{
		$registry = new MappingRegistry(new MappingReader(), null, '23.0.0');
		$file = (new \ReflectionClass(Article::class))->getFileName();
		self::assertIsString($file);

		self::assertSame('23.0.0|'.MappingCache::FORMAT.'|'.sha1_file($file), $registry->stamp(Article::class));
		self::assertNotSame($registry->stamp(Article::class), $registry->stamp(Author::class));
		self::assertSame('article', $registry->get(Article::class)->table);
	}

	public function testRegistryWorksWhenTheCacheCannotBeWritten(): void
	{
		$notADir = tempnam(sys_get_temp_dir(), 'orm-file');
		self::assertIsString($notADir);
		$log = ini_set('error_log', '/dev/null');
		try {
			$registry = new MappingRegistry(new MappingReader(), new MappingCache($notADir.'/cache/orm_mappings.php'), '23.0.0');
			self::assertSame('author', $registry->get(Author::class)->table);
		} finally {
			ini_set('error_log', (string) $log);
			unlink($notADir);
		}
	}
}
