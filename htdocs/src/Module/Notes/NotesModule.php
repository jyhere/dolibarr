<?php

declare(strict_types=1);

namespace Dolibarr\Module\Notes;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Entity\EntityRegistry;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTriggers;
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

	public function services(Container $c): void
	{
		$this->container = $c;
		$c->set(NotesRepository::class, static fn (Container $c): NotesRepository => new NotesRepository(self::get($c, Connection::class)));
		$c->set(NotesChrome::class, static fn (Container $c): NotesChrome => new NotesChrome(self::get($c, LegacyContext::class)));
		$c->set(NoteTriggerBridge::class, static fn (Container $c): NoteTriggerBridge => new NoteTriggerBridge(
			self::get($c, LegacyTriggers::class),
			self::get($c, LegacyContext::class),
			self::get($c, LegacyObjectLoader::class),
			self::get($c, Logger::class)
		));
		// The controller resolves the services of each entity (repository, checker, tabs) by their ids in the container
		$c->set(NotesController::class, static fn (Container $c): NotesController => new NotesController(
			self::get($c, EntityRegistry::class),
			$c,
			self::get($c, CurrentUser::class),
			self::get($c, LegacyHooks::class),
			self::get($c, LegacyObjectLoader::class),
			self::get($c, NotesRepository::class),
			self::get($c, NotesChrome::class),
			self::get($c, EventDispatcher::class),
			self::get($c, Connection::class),
			self::get($c, FlashBag::class),
			self::get($c, Translator::class),
			self::get($c, UrlGenerator::class)
		));
	}

	/**
	 * CoreServices calls services() then listeners(): the bridge comes from the container.
	 *
	 * @throws \LogicException When called before services()
	 */
	public function listeners(EventDispatcher $d): void
	{
		if ($this->container === null) {
			throw new \LogicException(self::class.'::services() must be called before listeners()');
		}
		self::get($this->container, NoteTriggerBridge::class)->register($d);
	}

	/**
	 * @return array<string, string>
	 */
	public function templateDirs(): array
	{
		return ['notes' => __DIR__.'/templates'];
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 * @throws \LogicException When the service has an unexpected type
	 */
	private static function get(Container $c, string $id): object
	{
		$service = $c->get($id);
		if (!$service instanceof $id) {
			throw new \LogicException('Service '.$id.' has an unexpected type');
		}

		return $service;
	}
}
