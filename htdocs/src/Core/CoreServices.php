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
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyInputFilter;
use Dolibarr\Core\Legacy\LegacyLayout;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacySession;
use Dolibarr\Core\Legacy\LegacyTranslator;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Module\ModuleInterface;
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
		Container $c,
		LegacyContext $ctx,
		Request $request,
		array $modules,
		array $definitions,
		string $dataRoot,
		string $urlRoot,
		bool $prod
	): void {
		$c->instance(Request::class, $request);
		$c->instance(LegacyContext::class, $ctx);
		$c->set(InputFilter::class, static fn (): InputFilter => new LegacyInputFilter());
		$c->set(Session::class, static fn (): Session => new LegacySession());
		$c->set(Config::class, static fn (): Config => new LegacyConfig($ctx));
		$c->set(Connection::class, static fn (): Connection => new Connection($ctx->db));
		$c->set(Translator::class, static fn (): Translator => new LegacyTranslator($ctx->langs));
		$user = $ctx->user;
		if ($user !== null && $user->id > 0) {
			$c->set(CurrentUser::class, static fn (): CurrentUser => new LegacyCurrentUser($user));
		}
		$c->set(LegacyHooks::class, static fn (): LegacyHooks => new LegacyHooks($ctx->hookmanager));
		$c->set(LegacyTriggers::class, static fn (): LegacyTriggers => new LegacyTriggers($ctx));
		$c->set(LegacyObjectLoader::class, static fn (Container $c): LegacyObjectLoader => new LegacyObjectLoader(self::get($c, Connection::class)));
		$c->set(FlashBag::class, static fn (): FlashBag => new FlashBag());
		$c->set(Confirm::class, static fn (): Confirm => new Confirm($ctx));
		$c->set(Logger::class, static fn (): Logger => new Logger());
		$c->set(LegacyLayout::class, static fn (): LegacyLayout => new LegacyLayout());
		$c->set(EventDispatcher::class, static fn (): EventDispatcher => new EventDispatcher());
		$c->set(UrlGenerator::class, static fn (): UrlGenerator => new UrlGenerator($definitions, $urlRoot));
		$c->set(DolibarrExtension::class, static function (Container $c): DolibarrExtension {
			$hooks = self::get($c, LegacyHooks::class);
			$legacyHook = static function (string $name, array $params) use ($hooks): string {
				$object = null;
				$action = '';

				$result = $hooks->execute($name, $params, $object, $action);

				// What a legacy template prints at the call point: the echoed output, then resPrint
				return $result->output.$result->print;
			};

			return new DolibarrExtension(
				self::get($c, Translator::class),
				$c->has(CurrentUser::class) ? self::get($c, CurrentUser::class) : null,
				self::get($c, UrlGenerator::class),
				$legacyHook
			);
		});

		$registry = new EntityRegistry();
		foreach ($modules as $module) {
			foreach ($module->entities() as $definition) {
				$registry->register($definition);
			}
		}
		$c->instance(EntityRegistry::class, $registry);
		$c->set(EntityServices::class, static fn (Container $c): EntityServices => new EntityServices($c));

		$templateDirs = ['core' => __DIR__.'/templates'];
		foreach ($modules as $module) {
			$templateDirs = array_merge($templateDirs, $module->templateDirs());
		}
		$c->set(LatteRenderer::class, static function (Container $c) use ($templateDirs, $dataRoot, $urlRoot, $prod): LatteRenderer {
			$renderer = new LatteRenderer($dataRoot.'/cache/latte', $templateDirs, self::get($c, DolibarrExtension::class), !$prod);
			$renderer->addGlobal('layout', self::get($c, LegacyLayout::class));
			$renderer->addGlobal('urlRoot', $urlRoot);

			return $renderer;
		});
		$c->set(Responder::class, static fn (Container $c): Responder => new Responder(self::get($c, LatteRenderer::class), $request));

		$dispatcher = self::get($c, EventDispatcher::class);
		foreach ($modules as $module) {
			$module->services($c);
			$module->listeners($dispatcher);
		}
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
