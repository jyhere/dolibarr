<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Entity;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Entity\EntityPermissions;
use Dolibarr\Core\Entity\EntityRepository;
use Dolibarr\Core\Entity\EntityServices;
use Dolibarr\Core\Entity\EntityTabs;
use Dolibarr\Core\Entity\LegacyPageSettings;
use PHPUnit\Framework\TestCase;

final class EntityServicesTest extends TestCase
{
	public function testResolvesTheTypedServices(): void
	{
		$repo = new class () implements EntityRepository {
			public function find(int $id): ?object
			{
				return null;
			}
		};
		$perms = new class () implements EntityPermissions {
			public function canRead(object $entity): bool
			{
				return true;
			}

			public function canEditNotes(object $entity): bool
			{
				return false;
			}
		};
		$tabs = new class () implements EntityTabs {
			public function chrome(object $legacyObject, object $entity, string $activeTab): array
			{
				return ['tabsHtml' => '', 'bannerHtml' => '', 'tabsEndHtml' => '', 'headerRowsHtml' => ''];
			}

			public function legacyPageSettings(string $page): LegacyPageSettings
			{
				return new LegacyPageSettings([], []);
			}
		};
		$container = new Container();
		$container->instance('repo', $repo);
		$container->instance('perms', $perms);
		$container->instance('tabs', $tabs);
		$services = new EntityServices($container);
		$definition = $this->definition();

		self::assertSame($repo, $services->repository($definition));
		self::assertSame($perms, $services->permissions($definition));
		self::assertSame($tabs, $services->tabs($definition));
	}

	public function testWrongRepositoryTypeThrows(): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Service repo of the entity thing must implement '.EntityRepository::class.', got stdClass');

		$this->withStdClass()->repository($this->definition());
	}

	public function testWrongPermissionsTypeThrows(): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage(EntityPermissions::class);

		$this->withStdClass()->permissions($this->definition());
	}

	public function testWrongTabsTypeThrows(): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage(EntityTabs::class);

		$this->withStdClass()->tabs($this->definition());
	}

	private function withStdClass(): EntityServices
	{
		$container = new Container();
		foreach (['repo', 'perms', 'tabs'] as $id) {
			$container->instance($id, new \stdClass());
		}

		return new EntityServices($container);
	}

	private function definition(): EntityDefinition
	{
		return new EntityDefinition('thing', \stdClass::class, 'repo', 'thing', 'thing', 'perms', 'tabs', 'thing_show');
	}
}
