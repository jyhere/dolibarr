<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityDefinition;

/**
 * Loads legacy objects, memoized per request (one instance per class and id).
 */
final class LegacyObjectLoader
{
	/** @var array<string,?\CommonObject> indexed by "class#id" */
	private array $objects = [];

	public function __construct(private readonly Connection $connection)
	{
	}

	/**
	 * @throws \LogicException When \User does not load as a \User
	 */
	public function user(int $id): ?\User
	{
		$user = $this->load(\User::class, '/user/class/user.class.php', $id);
		if ($user !== null && !$user instanceof \User) {
			throw new \LogicException('The legacy user loader did not return a \User');
		}

		return $user;
	}

	/**
	 * The legacy object of an entity type, from its EntityDefinition legacy class and file.
	 *
	 * @throws \LogicException When the definition declares no legacy class
	 */
	public function byElement(EntityDefinition $definition, int $id): ?\CommonObject
	{
		if ($definition->legacyClass === null || $definition->legacyClassFile === null) {
			throw new \LogicException('The entity '.$definition->slug.' declares no legacy class');
		}

		return $this->load($definition->legacyClass, $definition->legacyClassFile, $id);
	}

	/**
	 * require_once DOL_DOCUMENT_ROOT.$file, new $class($db), fetch($id): the object, null when fetch() finds nothing.
	 *
	 * @param class-string<\CommonObject> $class
	 *
	 * @throws \LogicException When $class is not a \CommonObject
	 */
	private function load(string $class, string $file, int $id): ?\CommonObject
	{
		$key = $class.'#'.$id;
		if (array_key_exists($key, $this->objects)) {
			return $this->objects[$key];
		}

		require_once DOL_DOCUMENT_ROOT.$file;
		if (!is_subclass_of($class, \CommonObject::class)) {
			throw new \LogicException($class.' is not a legacy \CommonObject');
		}
		$object = new $class($this->connection->legacy());

		return $this->objects[$key] = $object->fetch($id) > 0 ? $object : null;
	}
}
