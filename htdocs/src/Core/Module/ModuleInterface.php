<?php

declare(strict_types=1);

namespace Dolibarr\Core\Module;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Event\EventDispatcher;

/**
 * An MVC module: its controllers, services, event listeners and templates.
 */
interface ModuleInterface
{
	public function name(): string;

	/**
	 * @return list<class-string>
	 */
	public function controllers(): array;

	public function services(Container $c): void;

	public function listeners(EventDispatcher $d): void;

	/**
	 * @return list<EntityDefinition>
	 */
	public function entities(): array;

	/**
	 * @return array<string, string> slug => template dir
	 */
	public function templateDirs(): array;
}
