<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm;

use Dolibarr\Core\Orm\EntityMapping;
use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingReader;
use Dolibarr\Tests\Unit\Database\Fixture\BinderStatus;
use Dolibarr\Tests\Unit\Orm\Fixture\Article;
use Dolibarr\Tests\Unit\Orm\Fixture\ArticleStatus;
use Dolibarr\Tests\Unit\Orm\Fixture\Author;
use Dolibarr\Tests\Unit\Orm\Fixture\Tag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HydratorTest extends TestCase
{
	private EntityMapping $mapping;
	private Hydrator $hydrator;
	private string $timezone;

	protected function setUp(): void
	{
		$this->mapping = (new MappingReader())->read(Article::class);
		$this->hydrator = new Hydrator();
		$this->timezone = date_default_timezone_get();
	}

	protected function tearDown(): void
	{
		date_default_timezone_set($this->timezone);
	}

	/**
	 * Rows as the drivers give them: mysqli native numbers, pgsql and the fallback driver strings.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function rows(): iterable
	{
		yield 'native' => [[
			'rowid' => 7, 'title' => 'Hello', 'statut' => 1, 'price' => 1.5, 'featured' => 1, 'view_count' => 42,
			'fk_author' => 3, 'published_on' => '2026-03-29', 'datec' => '2026-03-29 02:30:00',
			'metadata' => '{"lang":"fr","path":"a/b","label":"été"}', 'entity' => 2, 'summary' => null,
		]];
		yield 'strings' => [[
			'rowid' => '7', 'title' => 'Hello', 'statut' => '1', 'price' => '1.50', 'featured' => '1', 'view_count' => '42',
			'fk_author' => '3', 'published_on' => '2026-03-29', 'datec' => '2026-03-29 02:30:00',
			'metadata' => '{"lang":"fr","path":"a/b","label":"été"}', 'entity' => '2', 'summary' => null,
		]];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	#[DataProvider('rows')]
	public function testEachCastFromBothDriverShapes(array $row): void
	{
		$article = $this->hydrator->hydrate($this->mapping, $row);

		self::assertInstanceOf(Article::class, $article);
		self::assertSame(7, $article->id);
		self::assertSame('Hello', $article->title);
		self::assertSame(ArticleStatus::Published, $article->status);
		self::assertSame(1.5, $article->price);
		self::assertTrue($article->featured);
		self::assertSame(42, $article->viewCount);
		self::assertSame(3, $article->authorId);
		self::assertEquals(new \DateTimeImmutable('2026-03-29 00:00:00', new \DateTimeZone('UTC')), $article->publishedOn);
		self::assertEquals(new \DateTimeImmutable('2026-03-29 02:30:00', new \DateTimeZone('UTC')), $article->createdAt);
		self::assertSame(['lang' => 'fr', 'path' => 'a/b', 'label' => 'été'], $article->metadata);
		self::assertSame(2, $article->entity);
		self::assertNull($article->summary);
		self::assertNull($article->author);
		self::assertSame([], $article->tags);
		self::assertSame([], $article->extraFields);
		self::assertFalse($article->hasSummary);

		self::assertSame([
			'rowid' => 7, 'title' => 'Hello', 'statut' => 1, 'price' => 1.5, 'featured' => 1, 'view_count' => 42,
			'fk_author' => 3, 'published_on' => '2026-03-29', 'datec' => '2026-03-29 02:30:00',
			'metadata' => '{"lang":"fr","path":"a/b","label":"été"}', 'entity' => 2, 'summary' => null,
		], $this->hydrator->extract($this->mapping, $article));
	}

	public function testLenientValuesFollowUserRepositoryRules(): void
	{
		$article = $this->hydrator->hydrate($this->mapping, [
			'rowid' => 'abc', 'title' => null, 'statut' => '0', 'price' => 'n/a', 'featured' => '0', 'view_count' => 'x',
			'fk_author' => '', 'published_on' => '', 'datec' => '0000-00-00 00:00:00', 'metadata' => null,
			'entity' => '1', 'summary' => 12,
		]);

		self::assertInstanceOf(Article::class, $article);
		self::assertNull($article->id);
		self::assertSame('', $article->title);
		self::assertSame(ArticleStatus::Draft, $article->status);
		self::assertNull($article->price);
		self::assertFalse($article->featured);
		self::assertSame(0, $article->viewCount);
		self::assertNull($article->authorId);
		self::assertNull($article->publishedOn);
		self::assertNull($article->createdAt);
		self::assertSame([], $article->metadata);
		self::assertSame('12', $article->summary);
	}

	public function testZeroDatesAndInvalidDatesAreNull(): void
	{
		foreach (['0000-00-00 00:00:00', '0000-00-00', '', 'not a date', null] as $value) {
			$article = $this->hydrator->hydrate($this->mapping, $this->row(['datec' => $value, 'published_on' => $value]));
			self::assertInstanceOf(Article::class, $article);
			self::assertNull($article->createdAt);
			self::assertNull($article->publishedOn);
		}
	}

	public function testBoolIsIntGreaterThanZero(): void
	{
		foreach ([[2, true], ['1', true], [0, false], ['0', false], ['-1', false], ['', false], [null, false]] as [$value, $expected]) {
			$article = $this->hydrator->hydrate($this->mapping, $this->row(['featured' => $value]));
			self::assertInstanceOf(Article::class, $article);
			self::assertSame($expected, $article->featured);
		}
	}

	public function testUnknownEnumValueIsRefused(): void
	{
		$this->expectException(\ValueError::class);
		$this->hydrator->hydrate($this->mapping, $this->row(['statut' => 9]));
	}

	public function testStringBackedAndNullableEnum(): void
	{
		$entity = new #[Table('thing')] class (1, BinderStatus::Enabled) {
			public function __construct(
				#[Id] public int $id,
				#[Column] public BinderStatus $state,
				#[Column] public ?BinderStatus $previousState = null
			) {
			}
		};
		$mapping = (new MappingReader())->read($entity::class);

		$thing = $this->hydrator->hydrate($mapping, ['rowid' => '1', 'state' => 'disabled', 'previous_state' => '']);

		self::assertInstanceOf($entity::class, $thing);
		self::assertSame(['rowid' => 1, 'state' => 'disabled', 'previous_state' => null], $this->hydrator->extract($mapping, $thing));
	}

	public function testDateRoundTripUnderParis(): void
	{
		date_default_timezone_set('Europe/Paris');

		// 02:30 does not exist in Paris that day (DST): the value must still come back unchanged.
		$article = $this->hydrator->hydrate($this->mapping, $this->row(['datec' => '2026-03-29 02:30:00', 'published_on' => '2026-03-29']));
		self::assertInstanceOf(Article::class, $article);
		$values = $this->hydrator->extract($this->mapping, $article);

		self::assertSame('2026-03-29 02:30:00', $values['datec']);
		self::assertSame('2026-03-29', $values['published_on']);
	}

	public function testExtractBindsDatesWithoutZoneConversion(): void
	{
		date_default_timezone_set('Europe/Paris');
		$article = new Article(
			id: null, title: 'T', status: ArticleStatus::Draft, price: null, featured: false, viewCount: 0, authorId: null,
			publishedOn: new \DateTimeImmutable('2026-07-01 23:30:00'), createdAt: new \DateTimeImmutable('2026-07-01 23:30:00'),
			metadata: [],
		);

		$values = $this->hydrator->extract($this->mapping, $article);

		self::assertNull($values['rowid']);
		self::assertSame(0, $values['statut']);
		self::assertSame(0, $values['featured']);
		self::assertSame('2026-07-01', $values['published_on']);
		self::assertSame('2026-07-01 23:30:00', $values['datec']);
		self::assertSame('[]', $values['metadata']);
		self::assertSame(1, $values['entity']);
	}

	public function testRelationAndExtraFieldValuesCanBeGiven(): void
	{
		$author = new Author(3, 'Ann');
		$tags = [new Tag(1, 'php')];

		$article = $this->hydrator->hydrate($this->mapping, $this->row([]), ['author' => $author, 'tags' => $tags, 'extraFields' => ['color' => 'red']]);

		self::assertInstanceOf(Article::class, $article);
		self::assertSame($author, $article->author);
		self::assertSame($tags, $article->tags);
		self::assertSame(['color' => 'red'], $article->extraFields);
	}

	public function testOnlyRelationsAndExtraFieldsCanBeGiven(): void
	{
		$this->expectException(\LogicException::class);
		$this->hydrator->hydrate($this->mapping, $this->row([]), ['hasSummary' => true]);
	}

	public function testMissingColumnUsesTheDefaultOrFails(): void
	{
		$row = $this->row([]);
		unset($row['summary'], $row['entity']);
		$article = $this->hydrator->hydrate($this->mapping, $row);
		self::assertInstanceOf(Article::class, $article);
		self::assertSame(1, $article->entity);
		self::assertNull($article->summary);

		unset($row['title']);
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessageMatches('/title/');
		$this->hydrator->hydrate($this->mapping, $row);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function row(array $overrides): array
	{
		return $overrides + [
			'rowid' => 7, 'title' => 'Hello', 'statut' => 1, 'price' => 1.5, 'featured' => 1, 'view_count' => 42,
			'fk_author' => 3, 'published_on' => '2026-03-29', 'datec' => '2026-03-29 02:30:00',
			'metadata' => '{}', 'entity' => 2, 'summary' => null,
		];
	}
}
