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
	public function repository(EntityDefinition $definition): EntityRepository
	{
		return $this->typed($definition, $definition->repositoryId, EntityRepository::class);
	}

	/**
	 * @throws \LogicException When the service is not an EntityPermissions
	 */
	public function permissions(EntityDefinition $definition): EntityPermissions
	{
		return $this->typed($definition, $definition->permissionCheckerId, EntityPermissions::class);
	}

	/**
	 * @throws \LogicException When the service is not an EntityTabs
	 */
	public function tabs(EntityDefinition $definition): EntityTabs
	{
		return $this->typed($definition, $definition->tabsFactoryId, EntityTabs::class);
	}

	/**
	 * @return EntityDocuments|null Null when the entity type has no attached files (no documentsId)
	 * @throws \LogicException When the service is not an EntityDocuments
	 */
	public function documents(EntityDefinition $definition): ?EntityDocuments
	{
		if ($definition->documentsId === null) {
			return null;
		}

		return $this->typed($definition, $definition->documentsId, EntityDocuments::class);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $type
	 * @return T
	 * @throws \LogicException When the service is not a $type
	 */
	private function typed(EntityDefinition $definition, string $id, string $type): object
	{
		$service = $this->container->get($id);
		if (!$service instanceof $type) {
			throw new \LogicException('Service '.$id.' of the entity '.$definition->slug.' must implement '.$type.', got '.$service::class);
		}

		return $service;
	}
}
