<?php

declare(strict_types=1);

namespace Dolibarr\Core\Entity;

use Dolibarr\Core\Container\Container;

/**
 * Resolves the services an EntityDefinition names, checking they honour their contract.
 */
final class EntityServices
{
	public function __construct(private readonly Container $container)
	{
	}

	/**
	 * @throws \LogicException When the service is not an EntityRepository
	 */
	public function repository(EntityDefinition $d): EntityRepository
	{
		return $this->typed($d, $d->repositoryId, EntityRepository::class);
	}

	/**
	 * @throws \LogicException When the service is not an EntityPermissions
	 */
	public function permissions(EntityDefinition $d): EntityPermissions
	{
		return $this->typed($d, $d->permissionCheckerId, EntityPermissions::class);
	}

	/**
	 * @throws \LogicException When the service is not an EntityTabs
	 */
	public function tabs(EntityDefinition $d): EntityTabs
	{
		return $this->typed($d, $d->tabsFactoryId, EntityTabs::class);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $type
	 * @return T
	 * @throws \LogicException When the service is not a $type
	 */
	private function typed(EntityDefinition $d, string $id, string $type): object
	{
		$service = $this->container->get($id);
		if (!$service instanceof $type) {
			throw new \LogicException('Service '.$id.' of the entity '.$d->slug.' must implement '.$type.', got '.$service::class);
		}

		return $service;
	}
}
