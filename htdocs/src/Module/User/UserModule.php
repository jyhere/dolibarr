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
use Dolibarr\Core\Logger;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
use Dolibarr\Core\Module\ModuleInterface;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Session;
use Dolibarr\Core\Translator;
use Dolibarr\Core\View\Confirm;
use Dolibarr\Module\User\Controller\LoginController;
use Dolibarr\Module\User\Controller\LogoutController;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Controller\UserListController;
use Dolibarr\Module\User\Controller\UserRightsController;
use Dolibarr\Module\User\Listener\LegacyTriggerBridge;
use Dolibarr\Module\User\Entity\User;
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
		return [new EntityDefinition('user', User::class, UserRepository::class, 'user', 'user', UserPermissionChecker::class, UserCardChrome::class, 'user_show', \User::class, '/user/class/user.class.php')];
	}

	public function services(Container $c): void
	{
		$this->container = $c;
		$c->set(LegacyTriggerBridge::class, static fn (Container $c): LegacyTriggerBridge => new LegacyTriggerBridge(
			self::get($c, LegacyTriggers::class),
			self::get($c, LegacyContext::class),
			self::get($c, LegacyObjectLoader::class),
			self::get($c, Logger::class)
		));
		$c->set(UserRepository::class, static function (Container $c): UserRepository {
			return new UserRepository(self::get($c, Connection::class), self::get($c, Config::class)->entity());
		});
		$c->set(UserWriter::class, static fn (Container $c): UserWriter => new LegacyUserWriter(
			self::get($c, LegacyContext::class),
			self::get($c, LegacyObjectLoader::class),
			self::get($c, Connection::class)
		));
		$c->set(UserService::class, static fn (Container $c): UserService => new UserService(
			self::get($c, UserRepository::class),
			self::get($c, UserWriter::class),
			self::get($c, EventDispatcher::class),
			self::get($c, Connection::class),
			self::get($c, CurrentUser::class)
		));
		// Configuration read when the service is built (after main.inc.php); the hierarchy from the legacy user.
		$c->set(UserPermissionChecker::class, static function (Container $c): UserPermissionChecker {
			$config = self::get($c, Config::class);
			$legacyUser = self::get($c, LegacyContext::class)->user;
			$subordinates = [];
			foreach ($legacyUser === null ? [] : $legacyUser->getAllChildIds(1) as $id) {
				$subordinates[] = (int) $id;
			}

			return new UserPermissionChecker(
				self::get($c, CurrentUser::class),
				$config->int('MAIN_USE_ADVANCED_PERMS') > 0,
				$config->isModuleEnabled('salaries'),
				$config->isModuleEnabled('hrm'),
				$subordinates,
				$config->isModuleEnabled('multicompany'),
				$config->string('MULTICOMPANY_TRANSVERSE_MODE') !== '',
				$config->entity()
			);
		});
		$c->set(UserCardChrome::class, static fn (Container $c): UserCardChrome => new UserCardChrome(self::get($c, LegacyContext::class), self::get($c, UrlGenerator::class)));
		$c->set(UserFormChrome::class, static fn (Container $c): UserFormChrome => new UserFormChrome(self::get($c, LegacyContext::class), self::get($c, UrlGenerator::class)));
		$c->set(UserValidator::class, static fn (Container $c): UserValidator => new UserValidator(self::get($c, Translator::class), self::get($c, Config::class)));
		$c->set(Authenticator::class, static function (Container $c): Authenticator {
			$ctx = self::get($c, LegacyContext::class);
			// main.inc.php l.541-554: the constant MAIN_AUTHENTICATION_MODE overrides $dolibarr_main_authentication
			$modes = defined('MAIN_AUTHENTICATION_MODE') ? constant('MAIN_AUTHENTICATION_MODE') : ($ctx->conf->file->main_authentication ?? 'dolibarr');

			return new Authenticator(
				$ctx,
				self::get($c, Session::class),
				self::get($c, Config::class),
				explode(',', is_string($modes) && $modes !== '' ? $modes : 'dolibarr')
			);
		});
		$c->set(SessionAuthenticator::class, static fn (Container $c): SessionAuthenticator => new SessionAuthenticator(
			self::get($c, LegacyContext::class),
			self::get($c, Session::class),
			self::get($c, Config::class),
			self::get($c, EventDispatcher::class),
			self::get($c, LegacyHooks::class),
			self::get($c, Connection::class),
			self::get($c, Logger::class)
		));
		$c->set(LegacyLoginPage::class, static fn (Container $c): LegacyLoginPage => new LegacyLoginPage(self::get($c, LegacyContext::class)));
		// The global $user goes to the controllers as the legacy object of their hooks and events
		$c->set(LoginController::class, static fn (Container $c): LoginController => new LoginController(
			self::get($c, Session::class),
			self::get($c, Config::class),
			self::get($c, Translator::class),
			self::get($c, Authenticator::class),
			self::get($c, SessionAuthenticator::class),
			self::get($c, EventDispatcher::class),
			self::get($c, LegacyHooks::class),
			self::get($c, LegacyLoginPage::class),
			self::get($c, Logger::class),
			self::get($c, LegacyContext::class)->user
		));
		$c->set(LogoutController::class, static fn (Container $c): LogoutController => new LogoutController(
			self::get($c, Session::class),
			self::get($c, Config::class),
			self::get($c, EventDispatcher::class),
			self::get($c, LegacyHooks::class),
			$c->has(CurrentUser::class) ? self::get($c, CurrentUser::class) : null,
			self::get($c, LegacyContext::class)->user
		));
		$c->set(UserController::class, static fn (Container $c): UserController => new UserController(
			self::get($c, UserRepository::class),
			self::get($c, UserPermissionChecker::class),
			self::get($c, CurrentUser::class),
			self::get($c, LegacyHooks::class),
			self::get($c, LegacyObjectLoader::class),
			self::get($c, Translator::class),
			self::get($c, UserCardChrome::class),
			self::get($c, FlashBag::class),
			self::get($c, UserService::class),
			self::get($c, UserValidator::class),
			self::get($c, UserFormChrome::class),
			self::get($c, Config::class),
			self::get($c, UrlGenerator::class),
			self::get($c, Confirm::class)
		));
		$c->set(UserListChrome::class, static fn (Container $c): UserListChrome => new UserListChrome(self::get($c, LegacyContext::class), self::get($c, UrlGenerator::class)));
		$c->set(UserListController::class, static fn (Container $c): UserListController => new UserListController(
			self::get($c, UserRepository::class),
			self::get($c, UserPermissionChecker::class),
			self::get($c, CurrentUser::class),
			self::get($c, LegacyHooks::class),
			self::get($c, Translator::class),
			self::get($c, UserListChrome::class),
			self::get($c, FlashBag::class),
			self::get($c, UserService::class),
			self::get($c, Config::class),
			self::get($c, UrlGenerator::class)
		));
		$c->set(RightsChrome::class, static fn (Container $c): RightsChrome => new RightsChrome(self::get($c, LegacyContext::class), self::get($c, Connection::class)));
		$c->set(RightsRepository::class, static fn (Container $c): RightsRepository => new RightsRepository(
			self::get($c, Connection::class),
			self::get($c, RightsChrome::class),
			self::get($c, Config::class)
		));
		$c->set(UserRightsController::class, static fn (Container $c): UserRightsController => new UserRightsController(
			self::get($c, UserRepository::class),
			self::get($c, UserPermissionChecker::class),
			self::get($c, CurrentUser::class),
			self::get($c, LegacyHooks::class),
			self::get($c, LegacyObjectLoader::class),
			self::get($c, Translator::class),
			self::get($c, UserCardChrome::class),
			self::get($c, RightsChrome::class),
			self::get($c, RightsRepository::class),
			self::get($c, UserService::class),
			self::get($c, FlashBag::class),
			self::get($c, Config::class),
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
		$id = ['id' => '\d+'];

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
			new LegacyRedirect('user/card.php', 'user_new', actions: ['create'], carry: ['backtopage', 'backtopageforcancel', 'socid', 'search_employee', 'mainmenu', 'leftmenu']),
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
	private static function get(Container $c, string $id): object
	{
		$service = $c->get($id);
		if (!$service instanceof $id) {
			throw new \LogicException('Service '.$id.' has an unexpected type');
		}

		return $service;
	}
}
