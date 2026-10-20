<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Controller;

use Dolibarr\Core\Config;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Entity\LegacyPageSettings;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\Response;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\HookResult;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Routing\Route;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Core\Translator;
use Dolibarr\Core\View\ViewModel;
use Dolibarr\Module\User\Entity\RightDefinition;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\RightsRepository;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\Service\ValidationException;
use Dolibarr\Module\User\View\RightsChrome;
use Dolibarr\Module\User\View\UserCardChrome;

/**
 * The rights tab of a user, htdocs/user/perms.php: the view (l.333-968) and the actions addrights and
 * delrights (l.103-169). The GET never writes users: the toggles of perms.php (GET links with a token) are
 * POSTed to update(). The legacy fixes of llx_rights_def that perms.php makes on every display are kept,
 * in RightsChrome::moduleDescriptors().
 */
final class UserRightsController
{
	private const ACTIONS = ['addrights', 'delrights'];

	public function __construct(
		private readonly UserRepository $repo,
		private readonly UserPermissionChecker $perms,
		private readonly CurrentUser $me,
		private readonly LegacyHooks $hooks,
		private readonly LegacyObjectLoader $legacy,
		private readonly Translator $t,
		private readonly UserCardChrome $tabs,
		private readonly RightsChrome $chrome,
		private readonly RightsRepository $rights,
		private readonly UserService $service,
		private readonly FlashBag $flash,
		private readonly Config $config,
		private readonly UrlGenerator $urls
	) {
	}

	/**
	 * The rights of the user, by module, with their state (perms.php l.497-959).
	 *
	 * @throws NotFoundException     When the user does not exist in the entity
	 * @throws AccessDeniedException When the current user may not read the user or his rights
	 */
	#[Route('/user/{id}/rights', methods: ['GET'], name: 'user_rights', requirements: ['id' => '\d+'])]
	public function show(Request $r, int $id): ViewModel
	{
		[$user, $settings, $legacyUser] = $this->prepare($id);
		// l.107-111: a GET runs doActions; its standard actions are POSTed to update()
		$action = $this->param($r, 'action', 'aZ09');
		$doActions = $this->doActions($settings, $legacyUser, $action);

		// l.100: always the current entity
		$entity = $this->config->entity();
		$advancedPerms = $this->config->int('MAIN_USE_ADVANCED_PERMS') > 0;
		$rightsCatalog = $this->rights->catalogFor($id, $entity, $advancedPerms);
		$catalog = $rightsCatalog->byModule;
		$meta = $this->chrome->moduleMeta($entity);
		$byEntity = $rightsCatalog->permsGroupByEntity;
		// l.631-637
		$hasGroupRights = ($byEntity[0] ?? []) !== [] || ($byEntity[$entity] ?? []) !== [];
		$canEdit = $this->perms->canEditRights($user);
		$meIsAdmin = $this->me->isAdmin();
		$targetIsAdmin = $user->admin;

		// The chrome first: it loads the rights of the legacy user (perms.php l.98), read by the warnings
		$chrome = $this->tabs->chrome($legacyUser, $user, 'rights');
		$fragments = $this->chrome->fragments($legacyUser, $meIsAdmin, $advancedPerms, $entity);

		// l.497-501
		$header = $this->executeHook('insertExtraHeader', ['permsgroupbyentity' => $byEntity], $legacyUser, $action);

		// l.553-554 and l.622-651: a module is collapsed by forbreakperms_<module>, else by the cookie (numbers of the collapsed modules)
		$cookie = preg_replace('/^,/', '', $r->cookie('DOLUSER_PERMS_HIDE_GRP') ?? '') ?? '';
		$collapsed = $cookie === '' ? [] : explode(',', $cookie);

		$modules = [];
		$exposed = [];
		$j = 0;
		foreach ($catalog as $module => $rights) {
			$j++;
			$moduleMeta = $meta[$module];
			$hidden = $r->isSet('forbreakperms_'.$module) ? $r->getInt('forbreakperms_'.$module) !== 0 : in_array((string) $j, $collapsed, true);
			$rows = [];
			foreach ($rights as $right) {
				$asAdmin = $targetIsAdmin && $moduleMeta['adminAllowed'];
				$rows[] = [
					'id' => $right->id,
					'perms' => $right->perms,
					'subperms' => $right->subperms,
					'granted' => $right->granted,
					'inherited' => $right->inheritedFromGroup,
					...$this->chrome->rightTexts($right, $legacyUser, $asAdmin || $right->granted || $right->inheritedFromGroup, $meIsAdmin, $advancedPerms, $entity),
				];
			}
			$modules[] = [
				'module' => $module,
				'j' => $j,
				'name' => $moduleMeta['name'],
				'pictoHtml' => $this->chrome->modulePicto($moduleMeta['picto']),
				'adminAllowed' => $moduleMeta['adminAllowed'],
				'hidden' => $hidden,
				'rights' => $rows,
			];
			$exposed[] = ['module' => $module, 'rights' => array_map(static fn (RightDefinition $d): array => $d->toArray(), $rights)];
		}

		// l.958-962
		$footer = $this->executeHook('insertExtraFooter', [], $legacyUser, $action);

		// l.339-342
		$personName = $user->firstname !== null && $user->firstname !== '' ? $user->lastname.', '.$user->firstname : (string) $user->lastname;

		return (new ViewModel('user:user_rights', [
			'outputBeforeHeader' => $doActions->output,
			'title' => $personName.' - '.$this->t->trans('Permissions'),
			'bodyCss' => 'mod-user page-card_perms',
			'formAction' => $this->urls->generate('user_rights_post', ['id' => $id]),
			'chrome' => $chrome,
			'f' => $fragments,
			'canEdit' => $canEdit,
			'meIsAdmin' => $meIsAdmin,
			'targetIsAdmin' => $targetIsAdmin,
			'hasGroupRights' => $hasGroupRights,
			'modules' => $modules,
			'headerHookHtml' => $header->output,
			'footerHookHtml' => $footer->output,
		]))->expose('rights', $exposed);
	}

	/**
	 * Actions addrights and delrights (perms.php l.113-169): one right (rights), the rights of a module
	 * (module) or all of them (module=allmodules), then the tab again without action, token nor confirm.
	 *
	 * @throws NotFoundException     When the user does not exist in the entity
	 * @throws AccessDeniedException When the current user may not read the user, or not edit his rights
	 */
	#[Route('/user/{id}/rights', methods: ['POST'], name: 'user_rights_post', requirements: ['id' => '\d+'])]
	public function update(Request $r, int $id): Response
	{
		[$user, $settings, $legacyUser] = $this->prepare($id);
		// l.134-138: the query string is kept, page_y included (added to the .reposition links by lib_foot.js.php l.230-245)
		$query = ['id' => $id];
		$updatedModule = $this->param($r, 'updatedmodulename', 'alpha');
		if ($updatedModule !== '') {
			$query['updatedmodulename'] = $updatedModule;
		}
		$pageY = $r->getInt('page_y');
		if ($pageY > 0) {
			$query['page_y'] = $pageY;
		}
		$back = new RedirectResponse($this->urls->generate('user_rights', $query));

		// Invalid CSRF token (main.inc.php l.456-478, its warning already set): doActions with an empty action, nothing written
		if ($r->get('errorcode', 'aZ09') === 'InvalidToken') {
			$this->doActions($settings, $legacyUser, '');

			return $back;
		}

		// l.107-113: the standard actions only when doActions returns 0
		$action = $this->param($r, 'action', 'aZ09');
		if ($this->doActions($settings, $legacyUser, $action)->code !== 0) {
			return $back;
		}
		if (!in_array($action, self::ACTIONS, true) || $this->param($r, 'confirm', 'alpha') !== 'yes') {
			return $back;
		}
		if (!$this->perms->canEditRights($user)) {
			throw new AccessDeniedException();
		}

		$rightId = $r->getInt('rights');
		$module = $this->param($r, 'module', 'alpha');
		try {
			// l.117 and l.145; the service reloads the rights and the menu of the current user when they are his (l.122-128)
			if ($action === 'addrights') {
				$this->service->addRights($id, $rightId > 0 ? $rightId : null, $module !== '' ? $module : null);
			} else {
				$this->service->delRights($id, $rightId > 0 ? $rightId : null, $module !== '' ? $module : null);
			}
		} catch (ValidationException $e) {
			foreach ($e->messages as $message) {
				$this->flash->error($message);
			}
		}

		return $back;
	}

	/**
	 * perms.php l.52-98: the user, readable by the current one with his rights (canRead() is the
	 * restrictedArea() of l.91, canReadRights() the refusals of l.84 and l.92), the domains, the hooks and
	 * the legacy user.
	 *
	 * @return array{User, LegacyPageSettings, \User}
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 */
	private function prepare(int $id): array
	{
		$user = $this->repo->find($id);
		if ($user === null) {
			throw new NotFoundException('User '.$id.' not found');
		}
		if (!$this->perms->canRead($user) || !$this->perms->canReadRights($user)) {
			throw new AccessDeniedException();
		}

		$settings = $this->tabs->legacyPageSettings('rights');
		foreach ([...$settings->translationDomains, 'errors'] as $domain) {
			$this->t->load($domain);
		}
		$this->hooks->init($settings->hookContexts);

		$legacyUser = $this->legacy->user($id);
		if ($legacyUser === null) {
			throw new NotFoundException('User '.$id.' not found');
		}

		return [$user, $settings, $legacyUser];
	}

	/**
	 * perms.php l.107-111.
	 */
	private function doActions(LegacyPageSettings $settings, \User $legacyUser, string $action): HookResult
	{
		return $this->executeHook('doActions', $settings->doActionsParams, $legacyUser, $action);
	}

	/**
	 * A hook of the page, its errors flashed when it fails (setEventMessages($hookmanager->error, $hookmanager->errors)).
	 *
	 * @param array<string, mixed> $parameters
	 */
	private function executeHook(string $name, array $parameters, \User $legacyUser, string $action): HookResult
	{
		$object = $legacyUser;
		$result = $this->hooks->execute($name, $parameters, $object, $action);
		if ($result->failed()) {
			foreach ($result->errors as $error) {
				$this->flash->error($error);
			}
		}

		return $result;
	}

	private function param(Request $r, string $name, string $filter): string
	{
		$value = $r->get($name, $filter);

		return is_string($value) ? $value : '';
	}
}
