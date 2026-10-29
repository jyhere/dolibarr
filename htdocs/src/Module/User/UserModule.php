<?php

declare(strict_types=1);

namespace Dolibarr\Module\User;

use Dolibarr\Core\Config;
use Dolibarr\Core\Container\Container;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
use Dolibarr\Core\Logger;
use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Session;
use Dolibarr\Core\Translator;
use Dolibarr\Core\View\Confirm;
use Dolibarr\Module\User\Controller\LoginController;
use Dolibarr\Module\User\Controller\LogoutController;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Controller\UserListController;
use Dolibarr\Module\User\Controller\UserRightsController;
use Dolibarr\Module\User\Documents\UserDocuments;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Listener\LegacyTriggerBridge;
use Dolibarr\Module\User\Repository\LegacyUserWriter;
use Dolibarr\Module\User\Repository\RightsRepository;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Repository\UserWriter;
use Dolibarr\Module\User\Security\Authenticator;
use Dolibarr\Module\User\Security\SessionAuthenticator;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\Service\UserValidator;
use Dolibarr\Module\User\View\LegacyLoginPage;
use Dolibarr\Module\User\View\RightsChrome;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Module\User\View\UserFormChrome;
use Dolibarr\Module\User\View\UserListChrome;

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
		return [UserController::class, UserListController::class, UserRightsController::class, LoginController::class, LogoutController::class];
	}

	/**
	 * @return list<EntityDefinition>
	 */
	public function entities(): array
	{
		return [new EntityDefinition('user', User::class, UserRepository::class, 'user', 'user', UserPermissionChecker::class, UserCardChrome::class, 'user_show', \User::class, '/user/class/user.class.php', UserDocuments::class)];
	}

	public function services(Container $container): void
	{
		$this->container = $container;
		$container->set(LegacyTriggerBridge::class, static fn (Container $container): LegacyTriggerBridge => new LegacyTriggerBridge(
			self::get($container, LegacyTriggers::class),
			self::get($container, LegacyContext::class),
			self::get($container, LegacyObjectLoader::class),
			self::get($container, Logger::class)
		));
		$container->set(UserRepository::class, static function (Container $container): UserRepository {
			return new UserRepository(
				self::get($container, Connection::class),
				self::get($container, Config::class)->entity(),
				self::get($container, Orm::class),
				self::get($container, Translator::class)
			);
		});
		$container->set(UserWriter::class, static fn (Container $container): UserWriter => new LegacyUserWriter(
			self::get($container, LegacyContext::class),
			self::get($container, LegacyObjectLoader::class),
			self::get($container, Connection::class)
		));
		$container->set(UserService::class, static fn (Container $container): UserService => new UserService(
			self::get($container, UserRepository::class),
			self::get($container, UserWriter::class),
			self::get($container, EventDispatcher::class),
			self::get($container, Connection::class),
			self::get($container, CurrentUser::class)
		));
		// Configuration read when the service is built (after main.inc.php); the hierarchy from the legacy user.
		$container->set(UserPermissionChecker::class, static function (Container $container): UserPermissionChecker {
			$config = self::get($container, Config::class);
			$legacyUser = self::get($container, LegacyContext::class)->user;
			$subordinates = [];
			foreach ($legacyUser === null ? [] : $legacyUser->getAllChildIds(1) as $id) {
				$subordinates[] = (int) $id;
			}

			return new UserPermissionChecker(
				self::get($container, CurrentUser::class),
				$config->int('MAIN_USE_ADVANCED_PERMS') > 0,
				$config->isModuleEnabled('salaries'),
				$config->isModuleEnabled('hrm'),
				$subordinates,
				$config->isModuleEnabled('multicompany'),
				$config->string('MULTICOMPANY_TRANSVERSE_MODE') !== '',
				$config->entity()
			);
		});
		$container->set(UserCardChrome::class, static fn (Container $container): UserCardChrome => new UserCardChrome(self::get($container, LegacyContext::class), self::get($container, UrlGenerator::class)));
		$container->set(UserDocuments::class, static fn (Container $container): UserDocuments => new UserDocuments(self::get($container, LegacyContext::class), self::get($container, UserPermissionChecker::class)));
		$container->set(UserFormChrome::class, static fn (Container $container): UserFormChrome => new UserFormChrome(self::get($container, LegacyContext::class), self::get($container, UrlGenerator::class)));
		$container->set(UserValidator::class, static fn (Container $container): UserValidator => new UserValidator(self::get($container, Translator::class), self::get($container, Config::class)));
		$container->set(Authenticator::class, static function (Container $container): Authenticator {
			$ctx = self::get($container, LegacyContext::class);
			// main.inc.php l.541-554: the constant MAIN_AUTHENTICATION_MODE overrides $dolibarr_main_authentication
			$modes = defined('MAIN_AUTHENTICATION_MODE') ? constant('MAIN_AUTHENTICATION_MODE') : ($ctx->conf->file->main_authentication ?? 'dolibarr');

			return new Authenticator(
				$ctx,
				self::get($container, Session::class),
				self::get($container, Config::class),
				explode(',', is_string($modes) && $modes !== '' ? $modes : 'dolibarr')
			);
		});
		$container->set(SessionAuthenticator::class, static fn (Container $container): SessionAuthenticator => new SessionAuthenticator(
			self::get($container, LegacyContext::class),
			self::get($container, Session::class),
			self::get($container, Config::class),
			self::get($container, EventDispatcher::class),
			self::get($container, LegacyHooks::class),
			self::get($container, Connection::class),
			self::get($container, Logger::class)
		));
		$container->set(LegacyLoginPage::class, static fn (Container $container): LegacyLoginPage => new LegacyLoginPage(self::get($container, LegacyContext::class)));
		// The global $user goes to the controllers as the legacy object of their hooks and events
		$container->set(LoginController::class, static fn (Container $container): LoginController => new LoginController(
			self::get($container, Session::class),
			self::get($container, Config::class),
			self::get($container, Translator::class),
			self::get($container, Authenticator::class),
			self::get($container, SessionAuthenticator::class),
			self::get($container, EventDispatcher::class),
			self::get($container, LegacyHooks::class),
			self::get($container, LegacyLoginPage::class),
			self::get($container, Logger::class),
			self::get($container, LegacyContext::class)->user
		));
		$container->set(LogoutController::class, static fn (Container $container): LogoutController => new LogoutController(
			self::get($container, Session::class),
			self::get($container, Config::class),
			self::get($container, EventDispatcher::class),
			self::get($container, LegacyHooks::class),
			$container->has(CurrentUser::class) ? self::get($container, CurrentUser::class) : null,
			self::get($container, LegacyContext::class)->user
		));
		$container->set(UserController::class, static fn (Container $container): UserController => new UserController(
			self::get($container, UserRepository::class),
			self::get($container, UserPermissionChecker::class),
			self::get($container, CurrentUser::class),
			self::get($container, LegacyHooks::class),
			self::get($container, LegacyObjectLoader::class),
			self::get($container, Translator::class),
			self::get($container, UserCardChrome::class),
			self::get($container, FlashBag::class),
			self::get($container, UserService::class),
			self::get($container, UserValidator::class),
			self::get($container, UserFormChrome::class),
			self::get($container, Config::class),
			self::get($container, UrlGenerator::class),
			self::get($container, Confirm::class)
		));
		$container->set(UserListChrome::class, static fn (Container $container): UserListChrome => new UserListChrome(self::get($container, LegacyContext::class), self::get($container, UrlGenerator::class)));
		$container->set(UserListController::class, static fn (Container $container): UserListController => new UserListController(
			self::get($container, UserRepository::class),
			self::get($container, UserPermissionChecker::class),
			self::get($container, CurrentUser::class),
			self::get($container, LegacyHooks::class),
			self::get($container, Translator::class),
			self::get($container, UserListChrome::class),
			self::get($container, FlashBag::class),
			self::get($container, UserService::class),
			self::get($container, Config::class),
			self::get($container, UrlGenerator::class)
		));
		$container->set(RightsChrome::class, static fn (Container $container): RightsChrome => new RightsChrome(self::get($container, LegacyContext::class), self::get($container, Connection::class)));
		$container->set(RightsRepository::class, static fn (Container $container): RightsRepository => new RightsRepository(
			self::get($container, Connection::class),
			self::get($container, RightsChrome::class),
			self::get($container, Config::class)
		));
		$container->set(UserRightsController::class, static fn (Container $container): UserRightsController => new UserRightsController(
			self::get($container, UserRepository::class),
			self::get($container, UserPermissionChecker::class),
			self::get($container, CurrentUser::class),
			self::get($container, LegacyHooks::class),
			self::get($container, LegacyObjectLoader::class),
			self::get($container, Translator::class),
			self::get($container, UserCardChrome::class),
			self::get($container, RightsChrome::class),
			self::get($container, RightsRepository::class),
			self::get($container, UserService::class),
			self::get($container, FlashBag::class),
			self::get($container, Config::class),
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
		self::get($this->container, LegacyTriggerBridge::class)->register($dispatcher);
	}

	/**
	 * @return array<string, string>
	 */
	public function templateDirs(): array
	{
		return ['user' => __DIR__.'/templates'];
	}

	/**
	 * @return list<LegacyRedirect>
	 */
	public function legacyRedirects(): array
	{
		// Arrays (search_category_user_list[]) are never carried: such a list URL stays on the legacy page (forbidden).
		$listCarry = [
			'search_all', 'search_user', 'search_rowid', 'search_login', 'search_lastname', 'search_firstname',
			'search_gender', 'search_employee', 'search_accountancy_code', 'search_phonepro', 'search_phonemobile',
			'search_email', 'search_country', 'search_api_key', 'search_status', 'search_thirdparty', 'search_job',
			'search_warehouse', 'search_supervisor', 'search_categ',
		];
		foreach (['datelastlogin', 'datepreviouslogin'] as $date) {
			foreach (['day', 'month', 'year'] as $part) {
				$listCarry[] = 'search_'.$date.$part;
			}
		}
		array_push(
			$listCarry,
			'search_category_user_operator', 'catid', 'sortfield', 'sortorder', 'limit', 'page', 'pageplusone',
			'optioncss', 'mode', 'contextpage', 'restore_lastsearch_values', 'mainmenu', 'leftmenu', 'show_files'
		);
		// id=0 (and a leading 0) stays legacy: card.php?id=0 is not a user card
		$id = ['id' => '[1-9]\d*'];

		return [
			new LegacyRedirect('user/card.php', 'user_show', ['id' => 'id'], required: ['id'], carry: ['backtopage', 'save_lastsearch_values', 'mainmenu', 'leftmenu', 'optioncss'], requirements: $id),
			new LegacyRedirect('user/card.php', 'user_edit', ['id' => 'id'], required: ['id'], actions: ['edit'], carry: ['backtopage'], requirements: $id),
			new LegacyRedirect(
				'user/card.php',
				'user_show',
				['id' => 'id'],
				required: ['id'],
				actions: ['disable', 'enable', 'delete', 'password', 'passwordsend'],
				queryFromAction: [
					'disable' => ['confirm' => 'disable'], 'enable' => ['confirm' => 'enable'], 'delete' => ['confirm' => 'delete'],
					'password' => ['confirm' => 'password'], 'passwordsend' => ['confirm' => 'passwordsend'],
				],
				requirements: $id
			),
			new LegacyRedirect('user/card.php', 'user_new', actions: ['create'], carry: ['backtopage', 'backtopageforcancel', 'socid', 'search_employee', 'mainmenu', 'leftmenu', 'employee']),
			new LegacyRedirect(
				'user/list.php',
				'user_list',
				forbidden: ['massaction', 'confirmmassaction', 'action', 'button_removefilter_x', 'button_removefilter.x', 'button_removefilter', 'formfilteraction', 'search_category_user_list'],
				carry: $listCarry,
				forbiddenValues: ['contextpage' => ['employeelist']],
				forbiddenPrefixes: ['search_options_']
			),
			new LegacyRedirect('user/note.php', 'entity_note', ['id' => 'id'], ['entity' => 'user'], ['id'], ['', 'editnote_public', 'editnote_private'], carry: ['action', 'token'], requirements: $id),
			new LegacyRedirect('user/perms.php', 'user_rights', ['id' => 'id'], required: ['id'], carry: ['updatedmodulename', 'page_y'], requirements: $id),
			new LegacyRedirect('user/logout.php', 'logout', actions: null, forbidden: [], carry: ['urlfrom', 'noredirect']),
		];
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
