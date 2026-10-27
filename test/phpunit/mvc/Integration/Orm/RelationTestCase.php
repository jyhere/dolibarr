<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Legacy\LegacyEntityScope;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Core\Orm\Snapshots;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmArticle;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmArticleRepository;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmBadPivotArticle;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmAuthorRepository;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmTestTables;
use Dolibarr\Tests\Integration\Orm\Fixture\RecordingDriver;
use PHPUnit\Framework\TestCase;

/**
 * Relations loaded by with(): one query per relation, on real tables of the connection's dialect. A subclass
 * gives the connection: MariaDB (Integration) and PostgreSQL (Pgsql).
 */
abstract class RelationTestCase extends TestCase
{
	private Connection $connection;

	private RecordingDriver $recorder;

	private OrmArticleRepository $articles;

	private OrmAuthorRepository $authors;

	abstract protected function connection(): Connection;

	protected function setUp(): void
	{
		$this->connection = $this->connection();
		OrmTestTables::create($this->connection);
		$this->recorder = new RecordingDriver($this->connection->driver());
		$hydrator = new Hydrator();
		$orm = new Orm(new Connection($this->connection->legacy(), $this->recorder), new MappingRegistry(), new LegacyEntityScope(), $hydrator, new Snapshots($hydrator));
		$this->articles = new OrmArticleRepository($orm);
		$this->authors = new OrmAuthorRepository($orm);
		// The extra field names are read from a row of the extra fields table, once per Orm and only when it has one:
		// a row and a first query keep that probe out of the statements the tests count.
		$this->connection->execute('INSERT INTO @mvc_orm_article_extrafields (fk_object) VALUES (0)');
		$this->articles->query();
		$this->recorder->statements = [];
	}

	protected function tearDown(): void
	{
		OrmTestTables::drop($this->connection);
	}

	public function testBelongsToLoadsInOneQuery(): void
	{
		$alice = $this->insertAuthor('Alice');
		$bob = $this->insertAuthor('Bob');
		for ($i = 1; $i <= 10; $i++) {
			$this->insertArticle('Article '.$i, $i % 2 === 0 ? $alice : $bob);
		}
		$this->recorder->statements = [];

		$articles = $this->articles->query()->orderBy('title')->with('author')->get();

		self::assertCount(10, $articles);
		self::assertCount(2, $this->recorder->statements, 'One query for the articles, one for the authors');
		self::assertStringContainsString('mvc_orm_author', $this->recorder->statements[1]);
		foreach ($articles as $article) {
			self::assertSame($article->authorId === $alice ? 'Alice' : 'Bob', $article->author?->name);
			self::assertSame($article->authorId, $article->author->id);
		}
		self::assertSame([], $articles[0]->tags, 'A relation not asked for keeps its default');
	}

	public function testHasMany(): void
	{
		$alice = $this->insertAuthor('Alice');
		$bob = $this->insertAuthor('Bob');
		$lonely = $this->insertAuthor('Lonely');
		$this->insertArticle('A1', $alice);
		$this->insertArticle('A2', $alice);
		$this->insertArticle('B1', $bob);
		$this->recorder->statements = [];

		$authors = $this->authors->query()->orderBy('name')->with('articles')->get();

		self::assertCount(2, $this->recorder->statements);
		self::assertSame(['Alice', 'Bob', 'Lonely'], array_map(static fn ($author): string => $author->name, $authors));
		self::assertSame(['A1', 'A2'], array_map(static fn (OrmArticle $article): string => $article->title, $authors[0]->articles));
		self::assertSame(['B1'], array_map(static fn (OrmArticle $article): string => $article->title, $authors[1]->articles));
		self::assertSame([], $authors[2]->articles);
		self::assertSame($lonely, $authors[2]->id);
	}

	public function testBelongsToMany(): void
	{
		$first = $this->insertArticle('First');
		$second = $this->insertArticle('Second');
		$this->insertArticle('Third');
		$php = $this->insertTag('php');
		$sql = $this->insertTag('sql');
		$this->insertTag('unused');
		$this->link($first, $php);
		$this->link($first, $sql);
		$this->link($second, $sql);
		$this->recorder->statements = [];

		$articles = $this->articles->query()->orderBy('title')->with('tags')->get();

		self::assertCount(3, $this->recorder->statements, 'Articles, pivot, tags');
		self::assertSame(['php', 'sql'], array_map(static fn ($tag): string => $tag->label, $articles[0]->tags));
		self::assertSame(['sql'], array_map(static fn ($tag): string => $tag->label, $articles[1]->tags));
		self::assertSame([], $articles[2]->tags);
	}

	public function testMissingTargetIsNull(): void
	{
		$this->insertArticle('Orphan', 9999);
		$this->insertArticle('No author');

		$articles = $this->articles->query()->orderBy('title')->with('author')->get();

		self::assertSame(['No author', 'Orphan'], array_map(static fn (OrmArticle $article): string => $article->title, $articles));
		self::assertNull($articles[0]->author);
		self::assertNull($articles[1]->author);
		self::assertSame(9999, $articles[1]->authorId);
	}

	public function testUnknownRelationThrows(): void
	{
		$this->insertArticle('Any');

		try {
			$this->articles->query()->with('nope');
			self::fail('Query::with() accepted an unknown relation');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('nope', $e->getMessage());
		}
		$loader = new \Dolibarr\Core\Orm\RelationLoader($this->orm());
		$mapping = $this->orm()->mappings()->get(OrmArticle::class);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('nope');
		$loader->load($mapping, $this->articles->query()->get(), ['nope']);
	}

	public function testNestedRelationRefused(): void
	{
		$this->insertArticle('Any');

		try {
			$this->articles->query()->with('author.articles');
			self::fail('Query::with() accepted a nested relation');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('nested', $e->getMessage());
		}
		$loader = new \Dolibarr\Core\Orm\RelationLoader($this->orm());
		$mapping = $this->orm()->mappings()->get(OrmArticle::class);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('nested');
		$loader->load($mapping, $this->articles->query()->get(), ['author.articles']);
	}

	public function testNoQueryWhenEmpty(): void
	{
		$none = $this->articles->query()->with('author', 'tags')->get();
		self::assertSame([], $none);
		self::assertCount(1, $this->recorder->statements, 'Only the articles query');

		$this->insertArticle('No author');
		$this->recorder->statements = [];
		$articles = $this->articles->query()->with('author')->get();
		self::assertCount(1, $articles);
		self::assertCount(1, $this->recorder->statements, 'No author id: no authors query');
	}

	public function testTargetRespectsItsOwnEntityScope(): void
	{
		$alice = $this->insertAuthor('Alice');
		$this->insertArticle('Visible', $alice);
		$this->insertArticle('Other company', $alice, entity: 2);

		$authors = $this->authors->query()->with('articles')->get();

		self::assertSame(['Visible'], array_map(static fn (OrmArticle $article): string => $article->title, $authors[0]->articles));
	}

	public function testRelationsWithPaginateAndFirstAndRepeatedIds(): void
	{
		$alice = $this->insertAuthor('Alice');
		$this->insertArticle('A', $alice);
		$this->insertArticle('B', $alice);
		$this->insertArticle('C', $alice);

		$page = $this->articles->query()->orderBy('title')->with('author')->paginate(1, 2);
		self::assertSame(3, $page->total);
		self::assertSame('Alice', $page->items[1]->author?->name);
		self::assertSame('Alice', $this->articles->query()->orderBy('title')->with('author')->first()?->author?->name);
	}

	public function testRebuiltEntityKeepsChangeTracking(): void
	{
		$alice = $this->insertAuthor('Alice');
		$tag = $this->insertTag('php');
		$id = $this->insertArticle('Before', $alice, extraFields: ['color' => 'red']);
		$this->link($id, $tag);
		$article = $this->articles->query()->where('id', $id)->with('author', 'tags')->first();
		self::assertNotNull($article);
		self::assertSame('Alice', $article->author?->name);
		self::assertSame(['color' => 'red'], array_intersect_key($article->extraFields, ['color' => 1]));

		$this->recorder->statements = [];
		$this->articles->save($article);
		self::assertSame([], $this->recorder->statements, 'Saving the loaded entity unchanged emits nothing');

		$renamed = $this->articles->modify($article, ['title' => 'After']);
		self::assertSame('Alice', $renamed->author?->name, 'modify() keeps the relations');
		$this->articles->save($renamed);
		self::assertCount(1, $this->recorder->statements);
		self::assertStringContainsString('SET title = ', $this->recorder->statements[0]);
		self::assertStringNotContainsString('fk_author', $this->recorder->statements[0]);
		self::assertSame('After', $this->connection->fetchValue('SELECT title FROM @mvc_orm_article WHERE rowid = :id', ['id' => $id]));
	}

	public function testBelongsToManyListsFollowTheTargetIdOrder(): void
	{
		$article = $this->insertArticle('Only');
		$first = $this->insertTag('first');
		$second = $this->insertTag('second');
		$third = $this->insertTag('third');
		$this->link($article, $third);
		$this->link($article, $first);
		$this->link($article, $second);

		$loaded = $this->articles->query()->with('tags')->first();

		self::assertSame([$first, $second, $third], array_map(static fn ($tag): ?int => $tag->id, $loaded->tags ?? []));
	}

	public function testBelongsToManyWithoutPivotRowRunsNoTargetQuery(): void
	{
		$this->insertArticle('One');
		$this->insertArticle('Two');
		$this->insertTag('lonely');
		$this->recorder->statements = [];

		$articles = $this->articles->query()->with('tags')->get();

		self::assertCount(2, $articles);
		self::assertSame([[], []], array_map(static fn (OrmArticle $article): array => $article->tags, $articles));
		self::assertCount(2, $this->recorder->statements, 'Articles and pivot, no tags query');
	}

	public function testDuplicateKeysGiveOneDeduplicatedQuery(): void
	{
		$alice = $this->insertAuthor('Alice');
		for ($i = 1; $i <= 5; $i++) {
			$this->insertArticle('A'.$i, $alice);
		}
		$this->recorder->statements = [];

		$articles = $this->articles->query()->with('author')->get();

		self::assertCount(2, $this->recorder->statements);
		self::assertSame(1, preg_match_all('/\?|\$\d+/', $this->recorder->statements[1]), 'One author id in the IN list: '.$this->recorder->statements[1]);
		self::assertSame('Alice', $articles[4]->author?->name);
	}

	public function testPaginateWithRelationRunsOneRelationQueryPerPage(): void
	{
		$alice = $this->insertAuthor('Alice');
		for ($i = 1; $i <= 5; $i++) {
			$this->insertArticle('A'.$i, $alice);
		}
		$this->recorder->statements = [];

		$page = $this->articles->query()->orderBy('title')->with('author')->paginate(2, 2);

		self::assertCount(2, $page->items);
		self::assertCount(3, $this->recorder->statements, 'Count, page, authors of the page');
	}

	public function testWithoutEntityScopeLoadsTargetsUnscoped(): void
	{
		$alice = $this->insertAuthor('Alice');
		$this->insertArticle('Visible', $alice);
		$this->insertArticle('Other company', $alice, entity: 2);

		$scoped = $this->authors->query()->with('articles')->first();
		$unscoped = $this->authors->withoutEntityScope()->query()->with('articles')->first();

		self::assertSame(['Visible'], array_map(static fn (OrmArticle $article): string => $article->title, $scoped?->articles ?? []));
		self::assertSame(['Other company', 'Visible'], $this->sortedTitles($unscoped?->articles ?? []));
	}

	public function testPivotIdentifiersAreValidated(): void
	{
		$mapping = $this->orm()->mappings()->get(OrmBadPivotArticle::class);
		$this->recorder->statements = [];

		try {
			(new \Dolibarr\Core\Orm\RelationLoader($this->orm()))->load($mapping, [new OrmBadPivotArticle(1, 'x')], ['tags']);
			self::fail('A pivot table that is not an identifier was accepted');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('pivot', $e->getMessage());
		}
		self::assertSame([], $this->recorder->statements);
	}

	private function orm(): Orm
	{
		$hydrator = new Hydrator();

		return new Orm(new Connection($this->connection->legacy(), $this->recorder), new MappingRegistry(), new LegacyEntityScope(), $hydrator, new Snapshots($hydrator));
	}

	/**
	 * @param list<OrmArticle> $articles
	 * @return list<string>
	 */
	private function sortedTitles(array $articles): array
	{
		$titles = array_map(static fn (OrmArticle $article): string => $article->title, $articles);
		sort($titles);

		return $titles;
	}

	private function insertAuthor(string $name): int
	{
		$this->connection->execute('INSERT INTO @mvc_orm_author (name) VALUES (:name)', ['name' => $name]);

		return $this->connection->lastInsertId('mvc_orm_author');
	}

	private function insertTag(string $label): int
	{
		$this->connection->execute('INSERT INTO @mvc_orm_tag (label) VALUES (:label)', ['label' => $label]);

		return $this->connection->lastInsertId('mvc_orm_tag');
	}

	private function link(int $articleId, int $tagId): void
	{
		$this->connection->execute('INSERT INTO @mvc_orm_article_tag (fk_article, fk_tag) VALUES (:article, :tag)', ['article' => $articleId, 'tag' => $tagId]);
	}

	/**
	 * @param array<string, mixed>|null $extraFields
	 */
	private function insertArticle(string $title, ?int $authorId = null, int $entity = 1, ?array $extraFields = null): int
	{
		$this->connection->execute(
			'INSERT INTO @mvc_orm_article (entity, title, fk_author) VALUES (:entity, :title, :author)',
			['entity' => $entity, 'title' => $title, 'author' => $authorId]
		);
		$id = $this->connection->lastInsertId('mvc_orm_article');
		if ($extraFields !== null) {
			$this->connection->execute(
				'INSERT INTO @mvc_orm_article_extrafields (fk_object, color) VALUES (:id, :color)',
				['id' => $id, 'color' => $extraFields['color'] ?? null]
			);
		}

		return $id;
	}
}
