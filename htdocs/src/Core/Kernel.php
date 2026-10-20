<?php

declare(strict_types=1);

namespace Dolibarr\Core;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Http\HtmlResponse;
use Dolibarr\Core\Http\JsonResponse;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\Response;
use Dolibarr\Core\Http\TextResponse;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyInputFilter;
use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Routing\ArgumentResolver;
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
 * match() runs BEFORE main.inc.php and must not depend on Dolibarr; its request
 * is only used for routing (method and path). run() runs after main.inc.php and
 * rebuilds the request, so that what main.inc.php neutralised (invalid CSRF
 * token: $_POST and some $_GET entries unset) never reaches a controller.
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
	 * True when $uri (request URI or path, query string ignored) names a static file: app.php
	 * answers such an unmatched URL with a bare 404, without main.inc.php (no session, no database).
	 * '.json' is in the list: app.php asks only when no route matched, so a JSON route is never concerned.
	 */
	public static function isStaticAssetPath(string $uri): bool
	{
		$path = rawurldecode(explode('#', explode('?', $uri, 2)[0], 2)[0]);

		return preg_match('#\.(png|jpe?g|gif|svg|webp|ico|css|js|map|woff2?|ttf|eot|txt|xml|json)$#i', $path) === 1;
	}

	/**
	 * The $_SERVER['PHP_SELF'] of a matched route: its path under the URL root. Legacy code
	 * reads PHP_SELF for form actions (login.tpl.php), the MAIN_LANDING_PAGE loop guard and
	 * log lines; behind app.php it would be '/app.php'. Null when nothing matched, or when the
	 * path holds characters a route path never holds (captured variables): PHP_SELF is then kept.
	 *
	 * @param MatchResult $matchResult
	 */
	public function phpSelfFor(array $matchResult): ?string
	{
		if ($matchResult['matched'] === null) {
			return null;
		}
		$path = $matchResult['request']->path();
		if (preg_match('#^[A-Za-z0-9_/.-]+$#', $path) !== 1) {
			return null;
		}

		return $this->urlRoot.$path;
	}

	/**
	 * Answer the request once main.inc.php has been included. Any uncaught error is logged
	 * and answered with a bare 500: its message (SQL, paths) never reaches the client.
	 *
	 * @param MatchResult $matchResult
	 * @param mixed       $mainIncResult Value returned by the include of main.inc.php
	 */
	public function run(array $matchResult, mixed $mainIncResult): Response
	{
		$request = $this->requestForRun();
		$responder = null;
		try {
			return $this->handle($matchResult, $mainIncResult, $request, $responder);
		} catch (\Throwable $e) {
			return $this->serverError($e, $request, $responder);
		}
	}

	/**
	 * @param MatchResult $matchResult
	 * @param-out ?Responder $responder The responder, once built
	 */
	private function handle(array $matchResult, mixed $mainIncResult, Request $request, ?Responder &$responder): Response
	{
		$error = $matchResult['error'];

		if ($error instanceof MethodNotAllowedException) {
			$allow = ['Allow' => implode(', ', $error->allowed())];
			if ($request->wantsJson()) {
				return new JsonResponse(['error' => 'method_not_allowed'], 405, $allow);
			}

			return new HtmlResponse('<!DOCTYPE html><html><body>405 Method Not Allowed</body></html>', 405, $allow);
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
		$service = $container->get(Responder::class);
		if (!$service instanceof Responder) {
			throw new \LogicException('Service '.Responder::class.' has an unexpected type');
		}
		$responder = $service;

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

			return $responder->respond(new ViewModel('core:error404', [], 404, ['error' => 'not_found']));
		} catch (AccessDeniedException $e) {
			if ($request->wantsJson()) {
				return new JsonResponse(['error' => 'forbidden'], 403);
			}
			// accessforbidden() prints the standard Dolibarr page then exits: it cannot be captured into a Response.
			http_response_code(403);
			accessforbidden($e->getMessage(), 1, 1, 1);
		}
	}

	/**
	 * Log $e (dol_syslog, else the PHP error log) and answer a 500 without any detail.
	 */
	private function serverError(\Throwable $e, Request $request, ?Responder $responder): Response
	{
		$message = 'Dolibarr MVC: uncaught '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine();
		if (function_exists('dol_syslog')) {
			(new Logger())->error($message);
		} else {
			error_log($message);
		}

		if ($request->wantsJson()) {
			return new JsonResponse(['error' => 'server_error'], 500);
		}
		if ($responder !== null) {
			try {
				return $responder->respond(new ViewModel('core:error500', [], 500));
			} catch (\Throwable $renderError) {
				error_log('Dolibarr MVC: error page not rendered: '.get_class($renderError).': '.$renderError->getMessage());
			}
		}

		return new TextResponse('Internal Server Error', 500);
	}

	/**
	 * Request rebuilt from the superglobals as left by main.inc.php, reading its parameters through
	 * GETPOST() (the legacy functions are loaded by then).
	 */
	public function requestForRun(): Request
	{
		return Request::fromGlobals($this->urlRoot)->withInputFilter(new LegacyInputFilter());
	}

	/**
	 * Invoke the controller action, resolving its arguments from its parameter list.
	 */
	private function dispatch(Container $container, Responder $responder, MatchedRoute $matched, Request $request): Response
	{
		$route = $matched->route;
		$controller = $container->get($route->controller);
		$method = new \ReflectionMethod($controller, $route->action);
		$result = $method->invokeArgs($controller, (new ArgumentResolver())->resolve($method, $matched, $request));
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
			try {
				$cache->store($definitions);
			} catch (\RuntimeException $e) {
				// The Logger service needs main.inc.php, not loaded yet: use the PHP error log.
				error_log('Dolibarr MVC: route cache not written, routes compiled for this request only: '.$e->getMessage());
			}
		}

		return $this->definitions = $definitions;
	}
}
