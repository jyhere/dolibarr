<?php

declare(strict_types=1);

namespace Dolibarr\Core;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityRegistry;
use Dolibarr\Core\Entity\EntityServices;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Http\InputFilter;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\LegacyConfig;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyCurrentUser;
use Dolibarr\Core\Legacy\LegacyEntityScope;
use Dolibarr\Core\Legacy\LegacyExtraFieldCatalog;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyInputFilter;
use Dolibarr\Core\Legacy\LegacyLayout;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacySession;
use Dolibarr\Core\Legacy\LegacyTranslator;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Orm\EntityScope;
use Dolibarr\Core\Orm\ExtraFieldCatalog;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingCache;
use Dolibarr\Core\Orm\MappingReader;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Core\Orm\Snapshots;
use Dolibarr\Core\Routing\RouteDefinition;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\Confirm;
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Core\View\LatteRenderer;
use Dolibarr\Core\View\Responder;

/**
 * Registers the core services, then the services and listeners of each module.
 */
final class CoreServices
{
	/**
	 * @param list<ModuleInterface>  $modules
	 * @param list<RouteDefinition>  $definitions
	 */
	public static function register(
		Container $container,
		LegacyContext $ctx,
		Request $request,
		array $modules,
		array $definitions,
		string $dataRoot,
		string $urlRoot,
		bool $prod
	): void {
		$container->instance(Request::class, $request);
		$container->instance(LegacyContext::class, $ctx);
		$container->set(InputFilter::class, static fn (): InputFilter => new LegacyInputFilter());
		$container->set(Session::class, static fn (): Session => new LegacySession());
		$container->set(Config::class, static fn (): Config => new LegacyConfig($ctx));
		$container->set(Connection::class, static fn (): Connection => Connection::forLegacy($ctx->db));
		$container->set(MappingRegistry::class, static fn (): MappingRegistry => new MappingRegistry(
			new MappingReader(),
			new MappingCache($dataRoot.'/cache/orm_mappings.php'),
			(string) \constant('DOL_VERSION')
		));
		$container->set(EntityScope::class, static fn (): EntityScope => new LegacyEntityScope());
		// One catalog per request: llx_extrafields read once per element type
		$container->set(ExtraFieldCatalog::class, static fn (Container $container): ExtraFieldCatalog => new LegacyExtraFieldCatalog(
			self::get($container, Connection::class),
			self::get($container, Config::class),
			self::get($container, Logger::class)
		));
		// One Orm, so one Snapshots, per request: shared by every repository
		$container->set(Orm::class, static function (Container $container): Orm {
			$hydrator = new Hydrator();

			return new Orm(
				self::get($container, Connection::class),
				self::get($container, MappingRegistry::class),
				self::get($container, EntityScope::class),
				$hydrator,
				new Snapshots($hydrator),
				self::get($container, ExtraFieldCatalog::class)
			);
		});
		$container->set(Translator::class, static fn (): Translator => new LegacyTranslator($ctx->langs));
		$user = $ctx->user;
		if ($user !== null && $user->id > 0) {
			$container->set(CurrentUser::class, static fn (): CurrentUser => new LegacyCurrentUser($user));
		}
		$container->set(LegacyHooks::class, static fn (): LegacyHooks => new LegacyHooks($ctx->hookmanager));
		$container->set(LegacyTriggers::class, static fn (): LegacyTriggers => new LegacyTriggers($ctx));
		$container->set(LegacyObjectLoader::class, static fn (Container $container): LegacyObjectLoader => new LegacyObjectLoader(self::get($container, Connection::class)));
		$container->set(FlashBag::class, static fn (): FlashBag => new FlashBag());
		$container->set(Confirm::class, static fn (): Confirm => new Confirm($ctx));
		$container->set(Logger::class, static fn (): Logger => new Logger());
		$container->set(LegacyLayout::class, static fn (): LegacyLayout => new LegacyLayout());
		$container->set(EventDispatcher::class, static fn (): EventDispatcher => new EventDispatcher());
		$container->set(UrlGenerator::class, static fn (): UrlGenerator => new UrlGenerator($definitions, $urlRoot));
		$container->set(DolibarrExtension::class, static function (Container $container): DolibarrExtension {
			$hooks = self::get($container, LegacyHooks::class);
			$legacyHook = static function (string $name, array $params) use ($hooks): string {
				$object = null;
				$action = '';

				$result = $hooks->execute($name, $params, $object, $action);

				// What a legacy template prints at the call point: the echoed output, then resPrint
				return $result->output.$result->print;
			};

			return new DolibarrExtension(
				self::get($container, Translator::class),
				$container->has(CurrentUser::class) ? self::get($container, CurrentUser::class) : null,
				self::get($container, UrlGenerator::class),
				$legacyHook
			);
		});

		$registry = new EntityRegistry();
		foreach ($modules as $module) {
			foreach ($module->entities() as $definition) {
				$registry->register($definition);
			}
		}
		$container->instance(EntityRegistry::class, $registry);
		$container->set(EntityServices::class, static fn (Container $container): EntityServices => new EntityServices($container));

		$templateDirs = ['core' => __DIR__.'/templates'];
		foreach ($modules as $module) {
			$templateDirs = array_merge($templateDirs, $module->templateDirs());
		}
		$container->set(LatteRenderer::class, static function (Container $container) use ($templateDirs, $dataRoot, $urlRoot, $prod): LatteRenderer {
			$renderer = new LatteRenderer($dataRoot.'/cache/latte', $templateDirs, self::get($container, DolibarrExtension::class), !$prod);
			$renderer->addGlobal('layout', self::get($container, LegacyLayout::class));
			$renderer->addGlobal('urlRoot', $urlRoot);

			return $renderer;
		});
		$container->set(Responder::class, static fn (Container $container): Responder => new Responder(self::get($container, LatteRenderer::class), $request));

		$dispatcher = self::get($container, EventDispatcher::class);
		foreach ($modules as $module) {
			$module->services($container);
			$module->listeners($dispatcher);
		}
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
