<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\CoreServices;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyEntityScope;
use Dolibarr\Core\Legacy\LegacyExtraFieldCatalog;
use Dolibarr\Core\Orm\EntityScope;
use Dolibarr\Core\Orm\ExtraFieldCatalog;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Tests\Integration\Orm\Fixture\OrmArticle;
use PHPUnit\Framework\TestCase;

/**
 * The ORM services of CoreServices: one Orm per request, the mapping registry cached in
 * <dataRoot>/cache/orm_mappings.php.
 */
final class OrmServicesTest extends TestCase
{
	private string $dataRoot;

	protected function setUp(): void
	{
		$this->dataRoot = sys_get_temp_dir().'/mvc-orm-services-'.bin2hex(random_bytes(4));
	}

	protected function tearDown(): void
	{
		@unlink($this->dataRoot.'/cache/orm_mappings.php');
		@rmdir($this->dataRoot.'/cache');
		@rmdir($this->dataRoot);
	}

	public function testOrmServicesAreRegistered(): void
	{
		$container = new Container();
		CoreServices::register($container, LegacyContext::fromGlobals(), new Request('GET', '/', [], [], [], []), [], [], $this->dataRoot, '', false);

		self::assertInstanceOf(LegacyEntityScope::class, $container->get(EntityScope::class));
		self::assertInstanceOf(LegacyExtraFieldCatalog::class, $container->get(ExtraFieldCatalog::class));
		$orm = $container->get(Orm::class);
		self::assertInstanceOf(Orm::class, $orm);
		self::assertSame($orm, $container->get(Orm::class), 'One Orm (one Snapshots) per request');
		$mappings = $container->get(MappingRegistry::class);
		self::assertInstanceOf(MappingRegistry::class, $mappings);
		self::assertSame($mappings, $orm->mappings());

		self::assertSame('mvc_orm_article', $mappings->get(OrmArticle::class)->table);
		self::assertFileExists($this->dataRoot.'/cache/orm_mappings.php');
		self::assertStringContainsString(DOL_VERSION.'|', $mappings->stamp(OrmArticle::class));
	}
}
