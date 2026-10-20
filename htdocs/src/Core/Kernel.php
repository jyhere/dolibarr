<?php

declare(strict_types=1);

namespace Dolibarr\Core;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Http\HtmlResponse;
use Dolibarr\Core\Http\JsonResponse;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\Response;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Routing\MatchedRoute;
use Dolibarr\Core\Routing\MethodNotAllowedException;
use Dolibarr\Core\Routing\RouteCache;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\RouteDefinition;
use Dolibarr\Core\Routing\RouteMatcher;
use Dolibarr\Core\Routing\RouteNotFoundException;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Core\View\Responder;
use Dolibarr\Core\View\ViewModel;

/**
 * Front controller kernel.
 *
 * match() runs BEFORE main.inc.php and must not depend on Dolibarr;
 * run() runs after it and uses the legacy globals through LegacyContext.
 *
 * @phpstan-type MatchResult array{mode: string, matched: ?MatchedRoute, request: Request, error: ?\Throwable}
 */
final class Kernel
{
	/** @var list<ModuleInterface>|null */
	private ?array $modules;

	/** @var list<RouteDefinition>|null */
	private ?array $definitions = null;

	/**
	 * @param list<ModuleInterface>|null $modules Modules, loaded from src/modules.php when null
	 */
	public function __construct(
		private readonly string $documentRoot,
		private readonly string $dataRoot,
		private readonly string $urlRoot,
		private readonly bool $prod,
		?array $modules = null
	) {
		$this->modules = $modules;
	}

	/**
	 * Match the current request. Mode is PUBLIC when the route is public, when
	 * nothing matches (404) or on a method mismatch (405), PROTECTED otherwise.
	 *
	 * @return MatchResult
	 */
	public function match(): array
	{
		$request = Request::fromGlobals($this->urlRoot);
		try {
			$matched = (new RouteMatcher($this->definitions()))->match($request->method(), $request->path());
		} catch (RouteNotFoundException | MethodNotAllowedException $e) {
			return ['mode' => BootstrapMode::PUBLIC, 'matched' => null, 'request' => $request, 'error' => $e];
		}

		return [
			'mode' => $matched->route->public ? BootstrapMode::PUBLIC : BootstrapMode::PROTECTED,
			'matched' => $matched,
			'request' => $request,
			'error' => null,
		];
	}

	/**
	 * Answer the request once main.inc.php has been included.
	 *
	 * @param MatchResult $matchResult
	 * @param mixed       $mainIncResult Value returned by the include of main.inc.php
	 */
	public function run(array $matchResult, mixed $mainIncResult): Response
	{
		$request = $matchResult['request'];
		$error = $matchResult['error'];

		if ($error instanceof MethodNotAllowedException) {
			return new HtmlResponse('', 405, ['Allow' => implode(', ', $error->allowed())]);
		}

		if ($mainIncResult === 'ERROR_NOT_LOGGED') {
			if ($request->wantsJson()) {
				return new JsonResponse(['error' => 'not_logged'], 401);
			}
			$uri = $_SERVER['REQUEST_URI'] ?? '';

			return new RedirectResponse($this->urlRoot.'/login?backtopage='.rawurlencode(is_string($uri) ? $uri : ''));
		}

		$container = new Container();
		CoreServices::register(
			$container,
			LegacyContext::fromGlobals(),
			$request,
			$this->modules(),
			$this->definitions(),
			$this->dataRoot,
			$this->urlRoot,
			$this->prod
		);
		$responder = $container->get(Responder::class);
		if (!$responder instanceof Responder) {
			throw new \LogicException('Service '.Responder::class.' has an unexpected type');
		}

		try {
			if ($error !== null) {
				throw $error;
			}
			if ($matchResult['matched'] === null) {
				throw new RouteNotFoundException('No matched route');
			}

			return $this->dispatch($container, $responder, $matchResult['matched'], $request);
		} catch (RouteNotFoundException | NotFoundException) {
			$translator = $container->get(Translator::class);
			if ($translator instanceof Translator) {
				$translator->load('errors');
			}

			return $responder->respond(new ViewModel('core:error404', ['error' => 'not_found'], 404));
		} catch (AccessDeniedException $e) {
			// accessforbidden() prints the standard Dolibarr page then exits: it cannot be captured into a Response.
			http_response_code(403);
			accessforbidden($e->getMessage(), 1, 1, 1);
		}
	}

	/**
	 * Invoke the controller action, resolving its arguments from its parameter list.
	 */
	private function dispatch(Container $container, Responder $responder, MatchedRoute $matched, Request $request): Response
	{
		$route = $matched->route;
		$controller = $container->get($route->controller);
		$method = new \ReflectionMethod($controller, $route->action);

		$args = [];
		foreach ($method->getParameters() as $parameter) {
			$type = $parameter->getType();
			$typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
			$name = $parameter->getName();
			if ($typeName === Request::class) {
				$args[] = $request;
			} elseif (array_key_exists($name, $matched->params)) {
				$args[] = $typeName === 'int' ? (int) $matched->params[$name] : $matched->params[$name];
			} else {
				throw new \LogicException('Cannot resolve parameter $'.$name.' of '.$route->controller.'::'.$route->action.'()');
			}
		}

		$result = $method->invokeArgs($controller, $args);
		if ($result instanceof Response) {
			return $result;
		}
		if ($result instanceof ViewModel) {
			return $responder->respond($result);
		}

		throw new \LogicException($route->controller.'::'.$route->action.'() must return a Response or a ViewModel');
	}

	/**
	 * @return list<ModuleInterface>
	 */
	private function modules(): array
	{
		if ($this->modules === null) {
			$modules = require $this->modulesFile();
			if (!is_array($modules)) {
				throw new \LogicException($this->modulesFile().' must return an array of modules');
			}
			$list = [];
			foreach ($modules as $module) {
				if (!$module instanceof ModuleInterface) {
					throw new \LogicException($this->modulesFile().' must return only '.ModuleInterface::class.' instances');
				}
				$list[] = $module;
			}
			$this->modules = $list;
		}

		return $this->modules;
	}

	private function modulesFile(): string
	{
		return $this->documentRoot.'/src/modules.php';
	}

	/**
	 * Route definitions, from the cache when usable.
	 *
	 * @return list<RouteDefinition>
	 */
	private function definitions(): array
	{
		if ($this->definitions !== null) {
			return $this->definitions;
		}

		$modulesFromFile = $this->modules === null;
		$controllers = [];
		foreach ($this->modules() as $module) {
			foreach ($module->controllers() as $controller) {
				$controllers[] = $controller;
			}
		}

		$cache = new RouteCache($this->dataRoot.'/cache/routes.php');
		$definitions = null;
		if ($this->prod) {
			$definitions = $cache->load();
		} else {
			$files = $modulesFromFile ? [$this->modulesFile()] : [];
			foreach ($controllers as $controller) {
				$file = (new \ReflectionClass($controller))->getFileName();
				if ($file !== false) {
					$files[] = $file;
				}
			}
			if ($cache->isFresh($files)) {
				$definitions = $cache->load();
			}
		}

		if ($definitions === null) {
			$definitions = (new RouteCompiler())->compile($controllers);
			$cache->store($definitions);
		}

		return $this->definitions = $definitions;
	}
}
