<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm;

use Dolibarr\Core\Orm\EntityMapping;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingReader;
use Dolibarr\Core\Orm\Snapshots;
use Dolibarr\Tests\Unit\Orm\Fixture\Article;
use Dolibarr\Tests\Unit\Orm\Fixture\ArticleStatus;
use PHPUnit\Framework\TestCase;

final class SnapshotsTest extends TestCase
{
	private const ROW = [
		'rowid' => '7', 'title' => 'Hello', 'statut' => '1', 'price' => '1.50', 'featured' => '1', 'view_count' => '42',
		'fk_author' => null, 'published_on' => '2026-03-29', 'datec' => '2026-03-29 02:30:00',
		'metadata' => '{"b":1,"a":"x/y"}', 'entity' => '1', 'summary' => 'Sum',
	];

	private EntityMapping $mapping;
	private Hydrator $hydrator;
	private Snapshots $snapshots;
	private string $timezone;

	protected function setUp(): void
	{
		$this->mapping = (new MappingReader())->read(Article::class);
		$this->hydrator = new Hydrator();
		$this->snapshots = new Snapshots($this->hydrator);
		$this->timezone = date_default_timezone_get();
	}

	protected function tearDown(): void
	{
		date_default_timezone_set($this->timezone);
	}

	public function testUnchangedEntityHasNoChanges(): void
	{
		date_default_timezone_set('Europe/Paris');
		$article = $this->read(self::ROW);

		self::assertTrue($this->snapshots->has($article));
		self::assertSame([], $this->snapshots->changes($this->mapping, $article));
	}

	public function testOnlyModifiedColumns(): void
	{
		$article = $this->read(self::ROW);
		$modified = $this->copy($article, ['title' => 'Bye', 'status' => ArticleStatus::Draft, 'summary' => null]);
		$this->snapshots->remember($modified, self::ROW);

		self::assertSame(['title' => 'Bye', 'statut' => 0, 'summary' => null], $this->snapshots->changes($this->mapping, $modified));
	}

	public function testFloatAndDateEquality(): void
	{
		date_default_timezone_set('Europe/Paris');
		$article = $this->read(self::ROW);
		$same = $this->copy($article, [
			'price' => 1.5,
			'createdAt' => new \DateTimeImmutable('2026-03-29 02:30:00', new \DateTimeZone('UTC')),
			'publishedOn' => new \DateTimeImmutable('2026-03-29 00:00:00', new \DateTimeZone('UTC')),
			'metadata' => ['b' => 1, 'a' => 'x/y'],
		]);
		$this->snapshots->remember($same, self::ROW);

		self::assertSame([], $this->snapshots->changes($this->mapping, $same));

		$later = $this->copy($article, ['price' => 1.51, 'createdAt' => new \DateTimeImmutable('2026-03-29 02:30:01', new \DateTimeZone('UTC'))]);
		$this->snapshots->remember($later, self::ROW);
		self::assertSame(['price' => 1.51, 'datec' => '2026-03-29 02:30:01'], $this->snapshots->changes($this->mapping, $later));
	}

	public function testNativeAndStringSnapshotsAreEquivalent(): void
	{
		$article = $this->read(self::ROW);
		$this->snapshots->remember($article, ['rowid' => 7, 'statut' => 1, 'price' => 1.5, 'featured' => 1, 'view_count' => 42, 'entity' => 1] + self::ROW);

		self::assertSame([], $this->snapshots->changes($this->mapping, $article));
	}

	public function testEntityWithoutSnapshotIsNewAndHasEveryColumn(): void
	{
		$article = $this->hydrator->hydrate($this->mapping, self::ROW);

		self::assertFalse($this->snapshots->has($article));
		self::assertSame($this->hydrator->extract($this->mapping, $article), $this->snapshots->changes($this->mapping, $article));
	}

	public function testColumnMissingFromSnapshotIsChanged(): void
	{
		$row = self::ROW;
		unset($row['summary']);
		$article = $this->read(self::ROW);
		$this->snapshots->remember($article, $row);

		self::assertSame(['summary' => 'Sum'], $this->snapshots->changes($this->mapping, $article));
	}

	public function testForget(): void
	{
		$article = $this->read(self::ROW);
		$this->snapshots->forget($article);

		self::assertFalse($this->snapshots->has($article));
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function read(array $row): Article
	{
		$article = $this->hydrator->hydrate($this->mapping, $row);
		self::assertInstanceOf(Article::class, $article);
		$this->snapshots->remember($article, $row);

		return $article;
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	private function copy(Article $article, array $changes): Article
	{
		$arguments = $changes + get_object_vars($article);

		return new Article(...$arguments);
	}
}
