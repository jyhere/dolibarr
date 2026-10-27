<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Legacy\LegacyEntityScope;
use Dolibarr\Core\Orm\EntityNotFoundException;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Core\Orm\Page;
use Dolibarr\Core\Orm\Snapshots;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmArticle;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmArticleRepository;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmAuthor;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmTestTables;
use Dolibarr\Tests\Integration\Orm\Fixture\RecordingDriver;
use PHPUnit\Framework\TestCase;

/**
 * The generic Repository on real tables (created by the test in the dialect of the connection): reads, writes,
 * change tracking, multicompany scope (LegacyEntityScope, entity 1 of the test install) and extra fields. A
 * subclass gives the connection: MariaDB (Integration) and PostgreSQL (Pgsql).
 *
 * The repository runs on a Connection whose driver records the statements, to count the queries a save() emits.
 */
abstract class RepositoryTestCase extends TestCase
{
	private Connection $connection;

	private RecordingDriver $recorder;

	private OrmArticleRepository $articles;

	private string $timezone;

	abstract protected function connection(): Connection;

	protected function setUp(): void
	{
		$this->timezone = date_default_timezone_get();
		$this->connection = $this->connection();
		OrmTestTables::create($this->connection);
		$this->recorder = new RecordingDriver($this->connection->driver());
		$hydrator = new Hydrator();
		$orm = new Orm(new Connection($this->connection->legacy(), $this->recorder), new MappingRegistry(), new LegacyEntityScope(), $hydrator, new Snapshots($hydrator));
		$this->articles = new OrmArticleRepository($orm);
	}

	protected function tearDown(): void
	{
		date_default_timezone_set($this->timezone);
		OrmTestTables::drop($this->connection);
	}

	public function testFindAndFindOrFail(): void
	{
		$id = $this->insertArticle('Hello', status: 1, price: 12.5, publishedOn: '2026-03-29', createdAt: '2026-03-29 02:30:00', authorId: 4);

		$article = $this->articles->find($id);

		self::assertInstanceOf(OrmArticle::class, $article);
		self::assertSame($id, $article->id);
		self::assertSame('Hello', $article->title);
		self::assertSame(1, $article->status);
		self::assertSame(12.5, $article->price);
		self::assertSame('2026-03-29', $article->publishedOn?->format('Y-m-d'));
		self::assertSame('2026-03-29 02:30:00', $article->createdAt?->format('Y-m-d H:i:s'));
		self::assertSame(4, $article->authorId);
		self::assertSame([], $article->extraFields);
		self::assertSame($id, $this->articles->findOrFail($id)->id);
		self::assertNull($this->articles->find($id + 1000));

		$this->expectException(EntityNotFoundException::class);
		$this->articles->findOrFail($id + 1000);
	}

	public function testFindByWithOrder(): void
	{
		$first = $this->insertArticle('Alpha', status: 1);
		$this->insertArticle('Beta', status: 0);
		$third = $this->insertArticle('Gamma', status: 1);

		self::assertSame(['Gamma', 'Alpha'], $this->titles($this->articles->findBy(['status' => 1], ['title' => 'DESC'])));
		self::assertSame(['Alpha', 'Gamma'], $this->titles($this->articles->findBy(['id' => [$first, $third]], ['title' => 'ASC'])));
		self::assertSame([], $this->articles->findBy(['id' => []]));
		self::assertSame(['Alpha', 'Beta', 'Gamma'], $this->titles($this->articles->findBy([], ['title' => 'asc'])));

		$this->expectException(\InvalidArgumentException::class);
		$this->articles->findBy(['nope' => 1]);
	}

	public function testGetFirstCountExists(): void
	{
		$this->insertArticle('Alpha', status: 1);
		$this->insertArticle('Beta', status: 0);
		$this->insertArticle('Gamma', status: 1);

		$published = $this->articles->published()->orderBy('title', 'DESC');
		self::assertSame(['Gamma', 'Alpha'], $this->titles($published->get()));
		self::assertSame('Gamma', $published->first()?->title);
		self::assertCount(2, $published->get(), 'first() leaves the query unchanged');
		self::assertSame(2, $published->count());
		self::assertTrue($published->exists());

		$none = $this->articles->query()->where('title', 'like', 'Z%');
		self::assertNull($none->first());
		self::assertSame(0, $none->count());
		self::assertFalse($none->exists());
		self::assertSame([], $none->get());

		$rows = $this->articles->published()->select('title')
			->selectRaw('CASE WHEN t.status = :published THEN 1 ELSE 0 END', 'is_published', ['published' => 1])
			->orderBy('title')
			->rows();
		self::assertSame(['Alpha', 'Gamma'], array_column($rows, 'title'));
		self::assertSame([1, 1], array_map(static fn (array $row): int => (int) $row['is_published'], $rows));
	}

	public function testPaginate(): void
	{
		foreach (['A', 'B', 'C', 'D', 'E'] as $title) {
			$this->insertArticle($title);
		}

		$first = $this->articles->query()->orderBy('title')->paginate(1, 2);
		self::assertInstanceOf(Page::class, $first);
		self::assertSame(['A', 'B'], $this->titles($first->items));
		self::assertSame(5, $first->total);
		self::assertSame(1, $first->page);
		self::assertSame(2, $first->perPage);
		self::assertTrue($first->hasNext());

		$last = $this->articles->query()->orderBy('title')->paginate(3, 2);
		self::assertSame(['E'], $this->titles($last->items));
		self::assertFalse($last->hasNext());

		$exact = $this->articles->query()->orderBy('title')->paginate(1, 5);
		self::assertFalse($exact->hasNext());

		$beyond = $this->articles->query()->orderBy('title')->paginate(4, 2);
		self::assertSame([], $beyond->items);
		self::assertSame(5, $beyond->total);

		foreach ([[0, 2], [1, 0]] as [$page, $perPage]) {
			try {
				$this->articles->query()->paginate($page, $perPage);
				self::fail('Accepted page '.$page.' of '.$perPage);
			} catch (\InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testInsertReturnsEntityWithId(): void
	{
		$article = new OrmArticle(null, "It's new", price: 2.5, createdAt: new \DateTimeImmutable('2026-10-27 10:11:12', new \DateTimeZone('UTC')));

		$saved = $this->articles->save($article);

		self::assertNotSame($article, $saved);
		self::assertNull($article->id);
		self::assertIsInt($saved->id);
		self::assertGreaterThan(0, $saved->id);
		self::assertSame("It's new", $saved->title);
		self::assertSame(2.5, $saved->price);
		self::assertCount(1, $this->recorder->statements);
		$insert = $this->recorder->statements[0];
		self::assertStringStartsWith('INSERT INTO '.MAIN_DB_PREFIX.'mvc_orm_article (', $insert);
		foreach (['rowid', 'published_on', 'fk_author'] as $nullColumn) {
			self::assertStringNotContainsString($nullColumn, $insert, 'Null columns are left to their default');
		}

		$row = $this->connection->fetchOne('SELECT title, status, price, published_on, datec, entity FROM @mvc_orm_article WHERE rowid = :id', ['id' => $saved->id]);
		self::assertNotNull($row);
		self::assertSame("It's new", $row['title']);
		self::assertSame(0, (int) $row['status']);
		self::assertSame(2.5, (float) $row['price']);
		self::assertNull($row['published_on']);
		self::assertSame('2026-10-27 10:11:12', $row['datec']);
		self::assertSame(1, (int) $row['entity']);

		$this->recorder->statements = [];
		self::assertSame($saved, $this->articles->save($saved));
		self::assertSame([], $this->recorder->statements, 'The saved entity is tracked: saving it again emits nothing');
	}

	public function testUpdateOnlyChangedColumns(): void
	{
		$id = $this->insertArticle('Before', price: 3.0, publishedOn: '2026-01-02');
		$article = $this->articles->findOrFail($id);
		$this->recorder->statements = [];

		$renamed = $this->articles->modify($article, ['title' => 'After']);
		self::assertSame('After', $renamed->title);
		self::assertSame('Before', $article->title);
		$result = $this->articles->save($renamed);

		self::assertSame($renamed, $result);
		self::assertCount(1, $this->recorder->statements);
		self::assertMatchesRegularExpression('/^UPDATE '.MAIN_DB_PREFIX.'mvc_orm_article SET title = (\?|\$1) WHERE rowid = (\?|\$2(::integer)?) AND entity IN \((\?|\$3(::integer)?)\)$/', $this->recorder->statements[0]);
		self::assertSame('After', $this->connection->fetchValue('SELECT title FROM @mvc_orm_article WHERE rowid = :id', ['id' => $id]));

		$this->recorder->statements = [];
		$twice = $this->articles->modify($this->articles->modify($renamed, ['price' => 4.5]), ['publishedOn' => null]);
		$this->articles->save($twice);
		self::assertCount(1, $this->recorder->statements);
		self::assertStringContainsString('SET price = ', $this->recorder->statements[0]);
		self::assertStringContainsString(', published_on = ', $this->recorder->statements[0]);
		self::assertStringNotContainsString('title', $this->recorder->statements[0]);
		$row = $this->connection->fetchOne('SELECT title, price, published_on FROM @mvc_orm_article WHERE rowid = :id', ['id' => $id]);
		self::assertNotNull($row);
		self::assertSame(['After', 4.5, null], [$row['title'], (float) $row['price'], $row['published_on']]);

		try {
			$this->articles->modify($article, ['id' => $id + 1]);
			self::fail('modify() changed the id');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('id', $e->getMessage());
		}

		$this->expectException(\InvalidArgumentException::class);
		$this->articles->modify($article, ['nope' => 1]);
	}

	public function testSaveUnchangedEmitsNoQuery(): void
	{
		date_default_timezone_set('Europe/Paris');
		$id = $this->insertArticle('Same', status: 1, price: 1.1, publishedOn: '2026-03-29', createdAt: '2026-03-29 02:30:00', extraFields: ['color' => 'red', 'weight' => 5]);
		$article = $this->articles->findOrFail($id);
		$copy = $this->articles->modify($article, [
			'price' => 1.1,
			'createdAt' => new \DateTimeImmutable('2026-03-29 02:30:00', new \DateTimeZone('UTC')),
			'extraFields' => ['color' => 'red', 'weight' => 5],
		]);
		$this->recorder->statements = [];
		$lastQuery = $this->connection->legacy()->lastquery;

		self::assertSame($article, $this->articles->save($article));
		self::assertSame($copy, $this->articles->save($copy));

		self::assertSame([], $this->recorder->statements);
		self::assertSame($lastQuery, $this->connection->legacy()->lastquery, 'No BEGIN/COMMIT either');
	}

	public function testDetachedEntityWithIdUpdatesEveryColumn(): void
	{
		$id = $this->insertArticle('Stored', status: 1, extraFields: ['color' => 'red']);
		$this->recorder->statements = [];

		$this->articles->save(new OrmArticle($id, 'Rebuilt', extraFields: ['weight' => 7]));

		self::assertSame(1, $this->countArticles());
		$row = $this->connection->fetchOne('SELECT title, status FROM @mvc_orm_article WHERE rowid = :id', ['id' => $id]);
		self::assertNotNull($row);
		self::assertSame(['Rebuilt', 0], [$row['title'], (int) $row['status']]);
		self::assertSame(['color' => 'red', 'weight' => '7'], $this->articles->findOrFail($id)->extraFields, 'The existing extra fields row is updated');
	}

	public function testUpdateAndDeleteOutsideTheEntityScopeRefused(): void
	{
		$other = $this->insertArticle('Other company', entity: 2, extraFields: ['color' => 'red']);
		$attempts = [
			'update' => fn (): mixed => $this->articles->save(new OrmArticle($other, 'Hijacked', extraFields: ['color' => 'stolen'])),
			'update of the extra fields alone' => fn (): mixed => $this->articles->save($this->articles->modify(
				$this->articles->withoutEntityScope()->findOrFail($other),
				['extraFields' => ['color' => 'stolen', 'weight' => null]]
			)),
			'delete' => fn () => $this->articles->delete(new OrmArticle($other, 'Other company', entity: 2)),
		];
		foreach ($attempts as $label => $attempt) {
			try {
				$attempt();
				self::fail('Written outside the entity scope: '.$label);
			} catch (EntityNotFoundException $e) {
				self::assertSame($other, $e->id, $label);
			}
			$row = $this->connection->fetchOne('SELECT title, entity FROM @mvc_orm_article WHERE rowid = :id', ['id' => $other]);
			self::assertNotNull($row, $label);
			self::assertSame(['Other company', 2], [$row['title'], (int) $row['entity']], $label);
			self::assertSame('red', $this->connection->fetchValue('SELECT color FROM @mvc_orm_article_extrafields WHERE fk_object = :id', ['id' => $other]), $label);
		}

		$everyEntity = $this->articles->withoutEntityScope();
		$everyEntity->save(new OrmArticle($other, 'Administered', entity: 2, extraFields: ['color' => 'blue']));
		self::assertSame('Administered', $everyEntity->findOrFail($other)->title);
		self::assertSame(['color' => 'blue', 'weight' => null], $everyEntity->findOrFail($other)->extraFields);
		self::assertNull($this->articles->find($other), 'The repository itself keeps its scope');
		$everyEntity->delete($everyEntity->findOrFail($other));
		self::assertSame(0, $this->countArticles());
	}

	public function testUpdateOrDeleteOfAMissingIdThrows(): void
	{
		$missing = $this->insertArticle('Gone') + 1000;
		try {
			$this->articles->save(new OrmArticle($missing, 'Ghost', extraFields: ['color' => 'red']));
			self::fail('UPDATE of a missing id');
		} catch (EntityNotFoundException $e) {
			self::assertSame($missing, $e->id);
		}
		self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @mvc_orm_article_extrafields'), 'No orphan extra fields row');

		$this->expectException(EntityNotFoundException::class);
		$this->articles->delete(new OrmArticle($missing, 'Ghost'));
	}

	public function testDetachedSaveOfIdenticalValuesSucceeds(): void
	{
		$id = $this->insertArticle('Same', status: 1, price: 2.5, publishedOn: '2026-03-29');
		$this->recorder->statements = [];

		$this->articles->save(new OrmArticle($id, 'Same', status: 1, price: 2.5, publishedOn: new \DateTimeImmutable('2026-03-29', new \DateTimeZone('UTC'))));

		self::assertStringStartsWith('UPDATE ', $this->recorder->statements[0]);
		self::assertSame('Same', $this->connection->fetchValue('SELECT title FROM @mvc_orm_article WHERE rowid = :id', ['id' => $id]));
	}

	public function testDelete(): void
	{
		$id = $this->insertArticle('Doomed', extraFields: ['color' => 'red']);
		$keep = $this->insertArticle('Kept', extraFields: ['color' => 'blue']);

		$article = $this->articles->findOrFail($id);
		$this->recorder->statements = [];
		$this->articles->delete($article);

		self::assertCount(2, $this->recorder->statements);
		self::assertStringStartsWith('DELETE FROM '.MAIN_DB_PREFIX.'mvc_orm_article_extrafields WHERE fk_object = ', $this->recorder->statements[0], 'Extra fields deleted first');
		self::assertStringStartsWith('DELETE FROM '.MAIN_DB_PREFIX.'mvc_orm_article WHERE rowid = ', $this->recorder->statements[1]);
		self::assertNull($this->articles->find($id));
		self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @mvc_orm_article_extrafields WHERE fk_object = :id', ['id' => $id]));
		self::assertSame(['color' => 'blue', 'weight' => null], $this->articles->findOrFail($keep)->extraFields);

		$this->expectException(\InvalidArgumentException::class);
		$this->articles->delete(new OrmArticle(null, 'Never saved'));
	}

	public function testEntityScopeFiltersOtherEntities(): void
	{
		$mine = $this->insertArticle('Mine', entity: 1);
		$other = $this->insertArticle('Other', entity: 2);

		self::assertSame(['Mine'], $this->titles($this->articles->query()->get()));
		self::assertNull($this->articles->find($other));
		self::assertSame(1, $this->articles->query()->count());
		self::assertSame($mine, $this->articles->find($mine)?->id);

		self::assertSame(['Mine', 'Other'], $this->titles($this->articles->query()->withoutEntityScope()->orderBy('title')->get()));
		self::assertSame(2, $this->articles->query()->withoutEntityScope()->count());
	}

	public function testExtraFieldsReadAndWritten(): void
	{
		$withExtra = $this->insertArticle('With', extraFields: ['color' => 'red', 'weight' => 5]);
		$withoutExtra = $this->insertArticle('Without');

		$article = $this->articles->findOrFail($withExtra);
		self::assertSame(['color' => 'red', 'weight' => '5'], $article->extraFields, 'Raw values, as strings; rowid, fk_object, tms and import_key left out');
		self::assertSame([], $this->articles->findOrFail($withoutExtra)->extraFields);
		self::assertSame(['With'], $this->titles($this->articles->query()->where('ef.color', 'red')->get()));
		self::assertTrue($this->articles->query()->isSortable('ef.color'));

		$this->recorder->statements = [];
		$this->articles->save($this->articles->modify($article, ['extraFields' => ['color' => 'blue', 'weight' => '5']]));
		self::assertCount(2, $this->recorder->statements, 'The row checked in the entity scope, then only the changed extra field');
		self::assertStringStartsWith('SELECT 1 AS found FROM '.MAIN_DB_PREFIX.'mvc_orm_article WHERE rowid = ', $this->recorder->statements[0]);
		self::assertStringContainsString(' AND entity IN (', $this->recorder->statements[0]);
		self::assertMatchesRegularExpression('/^UPDATE '.MAIN_DB_PREFIX.'mvc_orm_article_extrafields SET color = \S+ WHERE fk_object = \S+$/', $this->recorder->statements[1]);
		self::assertSame(['color' => 'blue', 'weight' => '5'], $this->articles->findOrFail($withExtra)->extraFields);

		$this->articles->save($this->articles->modify($this->articles->findOrFail($withoutExtra), ['extraFields' => ['color' => 'green']]));
		self::assertSame(['color' => 'green', 'weight' => null], $this->articles->findOrFail($withoutExtra)->extraFields);

		$created = $this->articles->save(new OrmArticle(null, 'Created', extraFields: ['weight' => 9]));
		self::assertSame(['weight' => 9], $created->extraFields);
		self::assertNotNull($created->id);
		self::assertSame(['color' => null, 'weight' => '9'], $this->articles->findOrFail($created->id)->extraFields);

		$plain = $this->articles->save(new OrmArticle(null, 'Plain'));
		self::assertNotNull($plain->id);
		self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @mvc_orm_article_extrafields WHERE fk_object = :id', ['id' => $plain->id]), 'No extra fields, no row');

		foreach ([['rowid' => 1], ['fk_object' => 1], ['bad name' => 1], ['color' => ['array']]] as $invalid) {
			try {
				$this->articles->save(new OrmArticle(null, 'Invalid', extraFields: $invalid));
				self::fail('Accepted extra fields '.json_encode($invalid));
			} catch (\InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testQueryWithExtraFieldsAsSubquery(): void
	{
		$this->insertArticle('Red', status: 1, extraFields: ['color' => 'red']);
		$this->insertArticle('Draft', status: 0, extraFields: ['color' => 'red']);

		$published = $this->articles->query('sub')->select('id')->where('sub.status', 1)->where('ef.color', 'red');
		self::assertSame(['Red'], $this->titles($this->articles->query()->whereIn('id', $published)->get()));
		self::assertSame(1, $this->articles->query()->whereIn('id', $published)->count());
		self::assertTrue($this->articles->query()->whereExists($this->articles->query('sub')->select('id')->whereRaw('sub.rowid = t.rowid'))->exists());
	}

	public function testSaveInsideALegacyTransactionIsRolledBackWithIt(): void
	{
		$db = $this->connection->legacy();
		$db->begin();
		try {
			$saved = $this->articles->save(new OrmArticle(null, 'Nested', extraFields: ['color' => 'red']));
			self::assertNotNull($saved->id);
		} finally {
			$db->rollback();
		}

		self::assertSame(0, $this->countArticles());
		self::assertSame(0, (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @mvc_orm_article_extrafields'));
	}

	public function testSaveIsAtomic(): void
	{
		try {
			$this->articles->save(new OrmArticle(null, 'Half', extraFields: ['no_such_column' => 'x']));
			self::fail('The extra fields write did not fail');
		} catch (DatabaseException) {
			self::assertSame(0, $this->countArticles(), 'The INSERT of the main row is rolled back');
		}

		$id = $this->insertArticle('Kept');
		$article = $this->articles->findOrFail($id);
		try {
			$this->articles->save($this->articles->modify($article, ['title' => 'Lost', 'extraFields' => ['no_such_column' => 'x']]));
			self::fail('The extra fields write did not fail');
		} catch (DatabaseException) {
			self::assertSame('Kept', $this->connection->fetchValue('SELECT title FROM @mvc_orm_article WHERE rowid = :id', ['id' => $id]), 'The UPDATE is rolled back');
		}
	}

	public function testPartialRowIsNotSaved(): void
	{
		$id = $this->insertArticle('Partial', status: 1, price: 5.0);
		$partial = $this->articles->query()->select('id', 'title')->where('id', $id)->first();
		self::assertNotNull($partial);
		self::assertSame(0, $partial->status, 'Absent column: the constructor default');

		foreach ([$partial, $this->articles->modify($partial, ['title' => 'Renamed'])] as $entity) {
			try {
				$this->articles->save($entity);
				self::fail('A partial entity was saved');
			} catch (\LogicException $e) {
				self::assertStringContainsString('partial', $e->getMessage());
			}
		}
		self::assertSame(1, (int) $this->connection->fetchValue('SELECT status FROM @mvc_orm_article WHERE rowid = :id', ['id' => $id]));
	}

	public function testRelationsAreNotLoadedYet(): void
	{
		$this->insertArticle('Related');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('relations are loaded in Task 7');
		$this->articles->query()->with('author')->get();
	}

	public function testOtherEntitiesAndQueriesRefused(): void
	{
		$attempts = [
			'save another class' => fn (): mixed => $this->articles->save(new OrmAuthor(null, 'x')),
			'delete another class' => fn () => $this->articles->delete(new OrmAuthor(1, 'x')),
			'reserved alias' => fn (): mixed => $this->articles->query('ef'),
		];
		foreach ($attempts as $label => $attempt) {
			try {
				$attempt();
				self::fail('Accepted: '.$label);
			} catch (\InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}
	}

	/**
	 * @param array<string, int|string|null> $extraFields
	 */
	private function insertArticle(
		string $title,
		int $status = 0,
		?float $price = null,
		?string $publishedOn = null,
		?string $createdAt = null,
		?int $authorId = null,
		int $entity = 1,
		?array $extraFields = null
	): int {
		$this->connection->execute(
			'INSERT INTO @mvc_orm_article (entity, title, status, price, published_on, datec, fk_author) VALUES (:entity, :title, :status, :price, :published, :created, :author)',
			['entity' => $entity, 'title' => $title, 'status' => $status, 'price' => $price, 'published' => $publishedOn, 'created' => $createdAt, 'author' => $authorId]
		);
		$id = $this->connection->lastInsertId('mvc_orm_article');
		if ($extraFields !== null) {
			$this->connection->execute(
				'INSERT INTO @mvc_orm_article_extrafields (fk_object, import_key, color, weight) VALUES (:id, :import, :color, :weight)',
				['id' => $id, 'import' => 'imported', 'color' => $extraFields['color'] ?? null, 'weight' => $extraFields['weight'] ?? null]
			);
		}

		return $id;
	}

	private function countArticles(): int
	{
		return (int) $this->connection->fetchValue('SELECT COUNT(*) FROM @mvc_orm_article');
	}

	/**
	 * @param list<OrmArticle> $articles
	 * @return list<string>
	 */
	private function titles(array $articles): array
	{
		return array_map(static fn (OrmArticle $article): string => $article->title, $articles);
	}
}
