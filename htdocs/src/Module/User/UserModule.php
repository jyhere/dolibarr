<?php

declare(strict_types=1);

namespace Dolibarr\Module\User;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Translator;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\View\UserCardChrome;

final class UserModule implements ModuleInterface
{
	public function name(): string
	{
		return 'user';
	}

	/**
	 * @return list<class-string>
	 */
	public function controllers(): array
	{
		return [UserController::class];
	}

	public function services(Container $c): void
	{
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
		$c->set(UserController::class, static fn (Container $c): UserController => new UserController(
			self::get($c, UserRepository::class),
			self::get($c, UserPermissionChecker::class),
			self::get($c, CurrentUser::class),
			self::get($c, LegacyHooks::class),
			self::get($c, LegacyObjectLoader::class),
			self::get($c, Translator::class),
			self::get($c, UserCardChrome::class)
		));
	}

	public function listeners(EventDispatcher $d): void
	{
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
