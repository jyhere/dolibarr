<?php

declare(strict_types=1);

namespace Dolibarr\Module\User;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Database\Connection;
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
use Dolibarr\Module\User\Controller\LoginController;
use Dolibarr\Module\User\Controller\LogoutController;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Listener\LegacyTriggerBridge;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\Authenticator;
use Dolibarr\Module\User\Security\SessionAuthenticator;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\View\LegacyLoginPage;
use Dolibarr\Module\User\View\UserCardChrome;

final class UserModule implements ModuleInterface
{
	private ?Container $container = null;

	public function name(): string
	{
		return 'user';
	}

	/**
	 * @return list<class-string>
	 */
	public function controllers(): array
	{
		return [UserController::class, LoginController::class, LogoutController::class];
	}

	public function services(Container $c): void
	{
		$this->container = $c;
		$c->set(LegacyTriggerBridge::class, static fn (Container $c): LegacyTriggerBridge => new LegacyTriggerBridge(
			self::get($c, LegacyTriggers::class),
			self::get($c, LegacyContext::class)
		));
		$c->set(UserRepository::class, static function (Container $c): UserRepository {
			return new UserRepository(self::get($c, Connection::class), (int) self::get($c, LegacyContext::class)->conf->entity);
		});
		// The single legacy touch point of the wiring: configuration read through the legacy
		// functions, called when the service is built (after main.inc.php).
		$c->set(UserPermissionChecker::class, static function (Container $c): UserPermissionChecker {
			$legacyUser = self::get($c, LegacyContext::class)->user;
			$subordinates = [];
			foreach ($legacyUser === null ? [] : $legacyUser->getAllChildIds(1) as $id) {
				$subordinates[] = (int) $id;
			}

			return new UserPermissionChecker(
				self::get($c, CurrentUser::class),
				getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') > 0,
				isModEnabled('salaries'),
				isModEnabled('hrm'),
				$subordinates,
				isModEnabled('multicompany'),
				getDolGlobalString('MULTICOMPANY_TRANSVERSE_MODE') !== '',
				(int) self::get($c, LegacyContext::class)->conf->entity
			);
		});
		$c->set(UserCardChrome::class, static fn (Container $c): UserCardChrome => new UserCardChrome(self::get($c, LegacyContext::class), self::get($c, UrlGenerator::class)));
		$c->set(Authenticator::class, static function (Container $c): Authenticator {
			$ctx = self::get($c, LegacyContext::class);
			// main.inc.php l.541-554: the constant MAIN_AUTHENTICATION_MODE overrides $dolibarr_main_authentication
			$modes = defined('MAIN_AUTHENTICATION_MODE') ? constant('MAIN_AUTHENTICATION_MODE') : ($ctx->conf->file->main_authentication ?? 'dolibarr');

			return new Authenticator($ctx, explode(',', is_string($modes) && $modes !== '' ? $modes : 'dolibarr'));
		});
		$c->set(SessionAuthenticator::class, static fn (Container $c): SessionAuthenticator => new SessionAuthenticator(
			self::get($c, LegacyContext::class),
			self::get($c, EventDispatcher::class),
			self::get($c, LegacyHooks::class),
			self::get($c, Connection::class),
			self::get($c, Logger::class)
		));
		$c->set(LegacyLoginPage::class, static fn (Container $c): LegacyLoginPage => new LegacyLoginPage(self::get($c, LegacyContext::class)));
		$c->set(LoginController::class, static fn (Container $c): LoginController => new LoginController(
			self::get($c, LegacyContext::class),
			self::get($c, Authenticator::class),
			self::get($c, SessionAuthenticator::class),
			self::get($c, EventDispatcher::class),
			self::get($c, LegacyHooks::class),
			self::get($c, LegacyLoginPage::class),
			self::get($c, Logger::class),
			DOL_URL_ROOT
		));
		$c->set(LogoutController::class, static fn (Container $c): LogoutController => new LogoutController(
			self::get($c, LegacyContext::class),
			self::get($c, EventDispatcher::class),
			self::get($c, LegacyHooks::class),
			DOL_URL_ROOT,
			DOL_MAIN_URL_ROOT
		));
		$c->set(UserController::class, static fn (Container $c): UserController => new UserController(
			self::get($c, UserRepository::class),
			self::get($c, UserPermissionChecker::class),
			self::get($c, CurrentUser::class),
			self::get($c, LegacyHooks::class),
			self::get($c, LegacyObjectLoader::class),
			self::get($c, Translator::class),
			self::get($c, UserCardChrome::class),
			self::get($c, FlashBag::class)
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
		self::get($this->container, LegacyTriggerBridge::class)->register($d);
	}

	/**
	 * @return array<string, string>
	 */
	public function templateDirs(): array
	{
		return ['user' => __DIR__.'/templates'];
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
