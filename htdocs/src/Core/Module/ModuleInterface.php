<?php

declare(strict_types=1);

namespace Dolibarr\Core\Module;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;

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

	public function services(Container $container): void;

	public function listeners(EventDispatcher $dispatcher): void;

	/**
	 * @return list<EntityDefinition>
	 */
	public function entities(): array;

	/**
	 * @return array<string, string> slug => template dir
	 */
	public function templateDirs(): array;

	/**
	 * Legacy URLs (scripts of htdocs) redirected to routes of this module.
	 *
	 * @return list<LegacyRedirect>
	 */
	public function legacyRedirects(): array;
}
