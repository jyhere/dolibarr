<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm;

use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\ExtraFields;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;
use Dolibarr\Core\Orm\EntityMapping;
use Dolibarr\Core\Orm\MappingReader;
use Dolibarr\Core\Orm\RelationMapping;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Tests\Unit\Orm\Fixture\Article;
use Dolibarr\Tests\Unit\Orm\Fixture\ArticleStatus;
use Dolibarr\Tests\Unit\Orm\Fixture\Author;
use Dolibarr\Tests\Unit\Orm\Fixture\Tag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MappingReaderTest extends TestCase
{
	public function testTableElementAndId(): void
	{
		$mapping = (new MappingReader())->read(Article::class);

		self::assertSame(Article::class, $mapping->class);
		self::assertSame('article', $mapping->table);
		self::assertSame('blog_article', $mapping->element);
		self::assertSame('id', $mapping->idProperty);
		self::assertSame('rowid', $mapping->id()->name);
		self::assertTrue($mapping->entityScoped);
		self::assertSame('extraFields', $mapping->extraFieldsProperty);

		$author = (new MappingReader())->read(Author::class);
		self::assertSame('author', $author->element);
		self::assertFalse($author->entityScoped);
		self::assertNull($author->extraFieldsProperty);
	}

	public function testColumnsWithSnakeCaseDefaultNames(): void
	{
		$mapping = (new MappingReader())->read(Article::class);

		$names = array_map(static fn ($column): string => $column->name, $mapping->columns);
		self::assertSame([
			'id' => 'rowid',
			'title' => 'title',
			'status' => 'statut',
			'price' => 'price',
			'featured' => 'featured',
			'viewCount' => 'view_count',
			'authorId' => 'fk_author',
			'publishedOn' => 'published_on',
			'createdAt' => 'datec',
			'metadata' => 'metadata',
			'entity' => 'entity',
			'summary' => 'summary',
		], $names);
		self::assertArrayNotHasKey('hasSummary', $mapping->columns);
		self::assertArrayNotHasKey('author', $mapping->columns);
	}

	public function testColumnLookupByPropertyOrColumnName(): void
	{
		$mapping = (new MappingReader())->read(Article::class);

		self::assertSame('viewCount', $mapping->column('viewCount')?->property);
		self::assertSame('viewCount', $mapping->column('view_count')?->property);
		self::assertSame('status', $mapping->column('statut')?->property);
		self::assertNull($mapping->column('pass_crypted'));
		self::assertNull($mapping->column('author'));
	}

	public function testDeducedCasts(): void
	{
		$columns = (new MappingReader())->read(Article::class)->columns;

		self::assertSame('int', $columns['id']->cast);
		self::assertTrue($columns['id']->nullable);
		self::assertSame('string', $columns['title']->cast);
		self::assertFalse($columns['title']->nullable);
		self::assertSame('enum', $columns['status']->cast);
		self::assertSame(ArticleStatus::class, $columns['status']->enumClass);
		self::assertSame('float', $columns['price']->cast);
		self::assertSame('bool', $columns['featured']->cast);
		self::assertSame('int', $columns['viewCount']->cast);
		self::assertSame('date', $columns['publishedOn']->cast);
		self::assertSame('datetime', $columns['createdAt']->cast);
		self::assertTrue($columns['createdAt']->nullable);
		self::assertSame('json', $columns['metadata']->cast);
		self::assertNull($columns['title']->enumClass);
	}

	public function testRelations(): void
	{
		$relations = (new MappingReader())->read(Article::class)->relations;

		self::assertSame(['author', 'tags'], array_keys($relations));
		self::assertSame(RelationMapping::BELONGS_TO, $relations['author']->kind);
		self::assertSame(Author::class, $relations['author']->target);
		self::assertSame('fk_author', $relations['author']->foreignKey);
		self::assertSame(RelationMapping::BELONGS_TO_MANY, $relations['tags']->kind);
		self::assertSame(Tag::class, $relations['tags']->target);
		self::assertSame('article_tag', $relations['tags']->pivotTable);
		self::assertSame('fk_article', $relations['tags']->localKey);
		self::assertSame('fk_tag', $relations['tags']->foreignKey);

		$authorRelations = (new MappingReader())->read(Author::class)->relations;
		self::assertSame(RelationMapping::HAS_MANY, $authorRelations['articles']->kind);
		self::assertSame(Article::class, $authorRelations['articles']->target);
		self::assertSame('fk_author', $authorRelations['articles']->foreignKey);
		self::assertNull($authorRelations['articles']->pivotTable);
	}

	/**
	 * @return iterable<string, array{object}>
	 */
	public static function secretColumns(): iterable
	{
		yield 'pass' => [new #[Table('user', entityScoped: false)] class (1) {
			public function __construct(#[Id] public int $id, #[Column('pass')] public ?string $secret = null)
			{
			}
		}];
		yield 'pass_crypted' => [new #[Table('user', entityScoped: false)] class (1) {
			public function __construct(#[Id] public int $id, #[Column('pass_crypted')] public ?string $secret = null)
			{
			}
		}];
		yield 'pass_temp' => [new #[Table('user', entityScoped: false)] class (1) {
			public function __construct(#[Id] public int $id, #[Column('pass_temp')] public ?string $secret = null)
			{
			}
		}];
		yield 'api_key' => [new #[Table('user', entityScoped: false)] class (1) {
			public function __construct(#[Id] public int $id, #[Column('api_key')] public ?string $secret = null)
			{
			}
		}];
		yield 'openid' => [new #[Table('user', entityScoped: false)] class (1) {
			public function __construct(#[Id] public int $id, #[Column('openid')] public ?string $secret = null)
			{
			}
		}];
		yield 'ldap_sid' => [new #[Table('user', entityScoped: false)] class (1) {
			public function __construct(#[Id] public int $id, #[Column('ldap_sid')] public ?string $secret = null)
			{
			}
		}];
		yield 'upper case' => [new #[Table('user', entityScoped: false)] class (1) {
			public function __construct(#[Id] public int $id, #[Column('PASS_CRYPTED')] public ?string $secret = null)
			{
			}
		}];
	}

	#[DataProvider('secretColumns')]
	public function testSecretColumnsAreRefused(object $entity): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessageMatches('/secret/');
		(new MappingReader())->read($entity::class);
	}

	public function testSecretColumnNamedByPropertyIsRefused(): void
	{
		$entity = new #[Table('user', entityScoped: false)] class (1, 'x') {
			public function __construct(
				#[Id] public int $id,
				#[Column] public string $apiKey
			) {
			}
		};

		$this->expectException(\LogicException::class);
		(new MappingReader())->read($entity::class);
	}

	public function testMissingIdIsRefused(): void
	{
		$entity = new #[Table('user', entityScoped: false)] class ('x') {
			public function __construct(#[Column] public string $login)
			{
			}
		};

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessageMatches('/#\[Id\]/');
		(new MappingReader())->read($entity::class);
	}

	public function testMissingTableIsRefused(): void
	{
		$entity = new class (1) {
			public function __construct(#[Id] public int $id)
			{
			}
		};

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessageMatches('/#\[Table\]/');
		(new MappingReader())->read($entity::class);
	}

	public function testTwoPropertiesOnTheSameColumnAreRefused(): void
	{
		$entity = new #[Table('user', entityScoped: false)] class (1, 1, 1) {
			public function __construct(
				#[Id] public int $id,
				#[Column('statut')] public int $status,
				#[Column('statut')] public int $state
			) {
			}
		};

		$this->expectException(\LogicException::class);
		(new MappingReader())->read($entity::class);
	}

	public function testUnmappedConstructorParameterWithoutDefaultIsRefused(): void
	{
		$entity = new #[Table('user', entityScoped: false)] class (1, true) {
			public function __construct(
				#[Id] public int $id,
				public bool $computed
			) {
			}
		};

		$this->expectException(\LogicException::class);
		(new MappingReader())->read($entity::class);
	}

	public function testExtraFieldsMustBeAnArray(): void
	{
		$entity = new #[Table('user', entityScoped: false)] class (1) {
			public function __construct(
				#[Id] public int $id,
				#[ExtraFields] public ?string $extraFields = null
			) {
			}
		};

		$this->expectException(\LogicException::class);
		(new MappingReader())->read($entity::class);
	}

	public function testIncompatibleExplicitCastIsRefused(): void
	{
		$entity = new #[Table('user', entityScoped: false)] class (1, 'x') {
			public function __construct(
				#[Id] public int $id,
				#[Column(cast: 'datetime')] public string $createdAt
			) {
			}
		};

		$this->expectException(\LogicException::class);
		(new MappingReader())->read($entity::class);
	}

	public function testScopedMappingWithoutEntityColumnIsRefused(): void
	{
		// A multicompany table whose entity column is forgotten must not silently lose its scope
		$entity = new #[Table('societe')] class (1, 'x') {
			public function __construct(
				#[Id] public int $id,
				#[Column] public string $nom
			) {
			}
		};

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessageMatches('/^class@anonymous.*entity scoped mapping must map the entity column.*entityScoped: false/s');
		(new MappingReader())->read($entity::class);
	}

	public function testUnscopedMappingWithEntityColumnIsRefused(): void
	{
		$entity = new #[Table('societe', entityScoped: false)] class (1, 1) {
			public function __construct(
				#[Id] public int $id,
				#[Column] public int $entity
			) {
			}
		};

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessageMatches('/^class@anonymous.*entityScoped: false\)\] contradicts its mapped entity column/s');
		(new MappingReader())->read($entity::class);
	}

	public function testUnscopedMappingIsDeclaredAndSurvivesTheCacheArray(): void
	{
		$author = (new MappingReader())->read(Author::class);
		$article = (new MappingReader())->read(Article::class);

		self::assertFalse($author->entityScoped);
		self::assertTrue($article->entityScoped);
		self::assertFalse(EntityMapping::fromArray($author->toArray())->entityScoped);
		self::assertTrue(EntityMapping::fromArray($article->toArray())->entityScoped);
	}

	public function testCacheArrayWithoutTheScopeFlagIsRejected(): void
	{
		$data = (new MappingReader())->read(Article::class)->toArray();
		unset($data['entityScoped']);

		$this->expectException(\UnexpectedValueException::class);
		EntityMapping::fromArray($data);
	}

	public function testUserEntityShapeIsSupported(): void
	{
		$entity = new #[Table('user', element: 'user')] class (1, 1, 'admin') {
			public function __construct(
				#[Id, Column('rowid')] public readonly int $id,
				#[Column] public readonly int $entity,
				#[Column] public readonly string $login,
				#[Column('statut')] public readonly int $status = 1,
				#[Column] public readonly ?\DateTimeImmutable $dateStartValidity = null,
				public readonly bool $hasApiKey = false
			) {
			}
		};

		$mapping = (new MappingReader())->read($entity::class);

		self::assertSame(['id', 'entity', 'login', 'status', 'dateStartValidity'], array_keys($mapping->columns));
		self::assertFalse($mapping->id()->nullable);
	}

	public function testUserEntityIsMappedOnTheColumnsOfItsRepository(): void
	{
		$mapping = (new MappingReader())->read(User::class);

		self::assertSame('user', $mapping->table);
		self::assertSame('user', $mapping->element);
		self::assertSame('id', $mapping->idProperty);
		self::assertSame('rowid', $mapping->id()->name);
		self::assertTrue($mapping->entityScoped);
		// The list reads its extra fields with the legacy helpers: the entity does not map them
		self::assertNull($mapping->extraFieldsProperty);
		$columns = array_map(static fn ($column): string => $column->name, array_values($mapping->columns));
		self::assertSame(UserRepository::COLUMNS, $columns);
		// Computed flags, never mapped: their secret columns are not read
		foreach (['hasApiKey', 'isLdapManaged', 'extraFields'] as $property) {
			self::assertArrayNotHasKey($property, $mapping->columns);
		}
		self::assertSame('statut', $mapping->columns['status']->name);
		self::assertSame('bool', $mapping->columns['admin']->cast);
		self::assertSame('date', $mapping->columns['birth']->cast);
		self::assertSame('datetime', $mapping->columns['dateLastLogin']->cast);
		self::assertSame('float', $mapping->columns['salary']->cast);
		self::assertTrue($mapping->columns['countryId']->nullable);
		self::assertSame('fk_user', $mapping->columns['supervisorId']->name);
		self::assertSame('force_pass_change', $mapping->columns['forcePasswordChange']->name);
	}
}
