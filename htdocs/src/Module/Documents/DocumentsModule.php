<?php

declare(strict_types=1);

namespace Dolibarr\Module\Documents;

use Dolibarr\Core\Config;
use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Entity\EntityRegistry;
use Dolibarr\Core\Entity\EntityServices;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Files\EcmFileRepository;
use Dolibarr\Core\Files\ExternalLinkRepository;
use Dolibarr\Core\Files\FileIndex;
use Dolibarr\Core\Files\FileStorage;
use Dolibarr\Core\Files\Thumbnails;
use Dolibarr\Core\Files\UploadPolicy;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyFileObjects;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Translator;
use Dolibarr\Module\Documents\Controller\DocumentsController;
use Dolibarr\Module\Documents\Service\AttachedFilesService;
use Dolibarr\Module\Documents\Service\LinkWriter;
use Dolibarr\Module\Documents\View\DocumentsChrome;

/**
 * The attached files tab of every entity the other modules declare with an EntityDocuments (/{entity}/{id}/documents).
 * Declares no entity and no legacy redirection: the module owning the legacy page declares its redirection. The disk
 * storage (FileStorage, moving the uploads with PhpUploadedFileMover), the index and the thumbnails are core services.
 */
final class DocumentsModule implements ModuleInterface
{
	public function name(): string
	{
		return 'documents';
	}

	/**
	 * @return list<class-string>
	 */
	public function controllers(): array
	{
		return [DocumentsController::class];
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
		$container->set(LinkWriter::class, static fn (Container $container): LinkWriter => new LinkWriter(
			self::get($container, ExternalLinkRepository::class),
			self::get($container, Connection::class),
			self::get($container, LegacyTriggers::class),
			self::get($container, LegacyFileObjects::class),
			self::get($container, Config::class),
			self::get($container, LegacyContext::class)
		));
		$container->set(AttachedFilesService::class, static fn (Container $container): AttachedFilesService => new AttachedFilesService(
			self::get($container, FileStorage::class),
			self::get($container, FileIndex::class),
			self::get($container, Thumbnails::class),
			self::get($container, UploadPolicy::class),
			self::get($container, LinkWriter::class),
			self::get($container, ExternalLinkRepository::class),
			self::get($container, EcmFileRepository::class),
			self::get($container, LegacyHooks::class),
			self::get($container, Translator::class),
			self::get($container, Config::class)
		));
		$container->set(DocumentsChrome::class, static fn (Container $container): DocumentsChrome => new DocumentsChrome(
			self::get($container, LegacyContext::class),
			self::get($container, LegacyHooks::class)
		));
		$container->set(DocumentsController::class, static fn (Container $container): DocumentsController => new DocumentsController(
			self::get($container, EntityRegistry::class),
			self::get($container, EntityServices::class),
			self::get($container, LegacyHooks::class),
			self::get($container, LegacyObjectLoader::class),
			self::get($container, AttachedFilesService::class),
			self::get($container, DocumentsChrome::class),
			self::get($container, EcmFileRepository::class),
			self::get($container, ExternalLinkRepository::class),
			self::get($container, FlashBag::class),
			self::get($container, Translator::class),
			self::get($container, UrlGenerator::class),
			self::get($container, Config::class)
		));
	}

	public function listeners(EventDispatcher $dispatcher): void
	{
	}

	/**
	 * @return array<string, string>
	 */
	public function templateDirs(): array
	{
		return ['documents' => __DIR__.'/templates'];
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
