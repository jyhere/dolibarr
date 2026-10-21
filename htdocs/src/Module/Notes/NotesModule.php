<?php

declare(strict_types=1);

namespace Dolibarr\Module\Notes;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Entity\EntityRegistry;
use Dolibarr\Core\Entity\EntityServices;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
use Dolibarr\Core\Logger;
use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Translator;
use Dolibarr\Module\Notes\Controller\NotesController;
use Dolibarr\Module\Notes\Listener\NoteTriggerBridge;
use Dolibarr\Module\Notes\Repository\NotesRepository;
use Dolibarr\Module\Notes\View\NotesChrome;

/**
 * The notes tab of every entity the other modules declare (/{entity}/{id}/note). Declares no entity itself.
 */
final class NotesModule implements ModuleInterface
{
	private ?Container $container = null;

	public function name(): string
	{
		return 'notes';
	}

	/**
	 * @return list<class-string>
	 */
	public function controllers(): array
	{
		return [NotesController::class];
	}

	/**
	 * @return list<EntityDefinition>
	 */
	public function entities(): array
	{
		return [];
	}

	public function services(Container $container): void
	{
		$this->container = $container;
		$container->set(NotesRepository::class, static fn (Container $container): NotesRepository => new NotesRepository(self::get($container, Connection::class)));
		$container->set(NotesChrome::class, static fn (Container $container): NotesChrome => new NotesChrome(self::get($container, LegacyContext::class)));
		$container->set(NoteTriggerBridge::class, static fn (Container $container): NoteTriggerBridge => new NoteTriggerBridge(
			self::get($container, LegacyTriggers::class),
			self::get($container, LegacyContext::class),
			self::get($container, LegacyObjectLoader::class),
			self::get($container, Logger::class),
			self::get($container, EntityRegistry::class)
		));
		$container->set(NotesController::class, static fn (Container $container): NotesController => new NotesController(
			self::get($container, EntityRegistry::class),
			self::get($container, EntityServices::class),
			self::get($container, CurrentUser::class),
			self::get($container, LegacyHooks::class),
			self::get($container, LegacyObjectLoader::class),
			self::get($container, NotesRepository::class),
			self::get($container, NotesChrome::class),
			self::get($container, EventDispatcher::class),
			self::get($container, Connection::class),
			self::get($container, FlashBag::class),
			self::get($container, Translator::class),
			self::get($container, UrlGenerator::class)
		));
	}

	/**
	 * CoreServices calls services() then listeners(): the bridge comes from the container.
	 *
	 * @throws \LogicException When called before services()
	 */
	public function listeners(EventDispatcher $dispatcher): void
	{
		if ($this->container === null) {
			throw new \LogicException(self::class.'::services() must be called before listeners()');
		}
		self::get($this->container, NoteTriggerBridge::class)->register($dispatcher);
	}

	/**
	 * @return array<string, string>
	 */
	public function templateDirs(): array
	{
		return ['notes' => __DIR__.'/templates'];
	}

	/**
	 * @return list<LegacyRedirect>
	 */
	public function legacyRedirects(): array
	{
		return [];
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 * @throws \LogicException When the service has an unexpected type
	 */
	private static function get(Container $container, string $id): object
	{
		$service = $container->get($id);
		if (!$service instanceof $id) {
			throw new \LogicException('Service '.$id.' has an unexpected type');
		}

		return $service;
	}
}
