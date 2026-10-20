<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Controller;

use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\HookResult;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Routing\Route;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Core\Translator;
use Dolibarr\Core\View\ViewModel;
use Dolibarr\Module\User\Entity\UserView;
use Dolibarr\Module\User\Entity\Visibility;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\View\UserCardChrome;

final class UserController
{
	private const HOOK_CONTEXTS = ['usercard', 'globalcard'];

	public function __construct(
		private readonly UserRepository $repo,
		private readonly UserPermissionChecker $perms,
		private readonly CurrentUser $me,
		private readonly LegacyHooks $hooks,
		private readonly LegacyObjectLoader $legacy,
		private readonly Translator $t,
		private readonly UserCardChrome $chrome,
		private readonly FlashBag $flash
	) {
	}

	/**
	 * Read-only user card, the view mode of htdocs/user/card.php.
	 *
	 * @throws NotFoundException     When the user does not exist in the entity
	 * @throws AccessDeniedException When the current user may not read it
	 */
	#[Route('/user/{id}', name: 'user_show', requirements: ['id' => '\d+'])]
	public function show(Request $r, int $id): ViewModel
	{
		$user = $this->repo->find($id);
		if ($user === null) {
			throw new NotFoundException('User '.$id.' not found');
		}
		if (!$this->perms->canRead($user)) {
			throw new AccessDeniedException();
		}

		// card.php l.79
		foreach (['users', 'companies', 'ldap', 'admin', 'hrm', 'stocks', 'other'] as $domain) {
			$this->t->load($domain);
		}
		$this->hooks->init(self::HOOK_CONTEXTS);

		// Needed by the tabs and the banner, memoized: loaded once per request.
		$legacyUser = $this->legacy->user($id);
		if ($legacyUser === null) {
			throw new NotFoundException('User '.$id.' not found');
		}

		$canSeeSalary = $this->perms->canSeeSalary($user);
		if ($canSeeSalary) {
			$this->t->load('salaries');
		}

		$outputBeforeHeader = $hookFormObjectOptions = $hookMoreObjectBlock = $hookMoreActionsButtons = '';
		$showActions = true;
		if ($this->hooks->hasListenersFor(self::HOOK_CONTEXTS)) {
			$action = '';
			$object = $legacyUser;
			// card.php l.185 ($parameters, still current when extrafields_view.tpl.php runs formObjectOptions, l.2004);
			// $socid is the one of the current user (l.124-127), $group is GETPOSTINT('group') (l.86).
			$parameters = [
				'id' => $id,
				'socid' => $this->me->companyId(),
				'group' => (int) $r->query('group', '0'),
				'caneditgroup' => $this->perms->canEditGroups($user),
			];

			// card.php l.185-189, the Actions section. A hook returning > 0 replaces the standard actions:
			// a GET show runs none, so the card is rendered in both cases. What it echoes, card.php prints
			// before llxHeader().
			$doActions = $this->hooks->execute('doActions', $parameters, $object, $action);
			$this->flashErrors($doActions);
			$outputBeforeHeader = $doActions->output;

			// extrafields_view.tpl.php l.72-76: echoed output, then resPrint, always
			$options = $this->hooks->execute('formObjectOptions', $parameters, $object, $action);
			$this->flashErrors($options);
			$hookFormObjectOptions = $options->output.$options->print;

			// card.php l.2263-2268: resPrint printed only when the hook replaces
			$block = $this->hooks->execute('addMoreObjectBlock', [
				'caneditpasswordandsee' => $this->perms->canSetPassword($user),
				'caneditpasswordandsend' => $this->perms->canSendPassword($user),
			], $object, $action);
			$hookMoreObjectBlock = $block->output.($block->code > 0 ? $block->print : '');

			// card.php l.2282-2394: standard buttons only when no hook replaces them; resPrint is never printed
			$buttons = $this->hooks->execute('addMoreActionsButtons', [], $object, $action);
			$hookMoreActionsButtons = $buttons->output;
			$showActions = $buttons->code === 0;
		}

		$visibility = match (true) {
			$this->me->isAdmin() => Visibility::Admin,
			$this->me->id() === $user->id => Visibility::SameUser,
			default => Visibility::Public,
		};

		$personName = $user->firstname !== null && $user->firstname !== '' ? $user->lastname.', '.$user->firstname : (string) $user->lastname;

		return (new ViewModel('user:user_show', [
			'outputBeforeHeader' => $outputBeforeHeader,
			'title' => $personName.' - '.$this->t->trans('Card'),
			'bodyCss' => 'mod-user page-card',
			'canSeeSalary' => $canSeeSalary,
			'card' => $this->chrome->build($legacyUser, $user, $this->perms, $canSeeSalary, $this->repo->countInEntity()),
			'showActions' => $showActions,
			'hookFormObjectOptions' => $hookFormObjectOptions,
			'hookMoreObjectBlock' => $hookMoreObjectBlock,
			'hookMoreActionsButtons' => $hookMoreActionsButtons,
		]))->expose('user', new UserView($user, $visibility, $canSeeSalary));
	}

	/**
	 * setEventMessages($hookmanager->error, $hookmanager->errors, 'errors') of a failed hook.
	 */
	private function flashErrors(HookResult $result): void
	{
		if (!$result->failed()) {
			return;
		}
		foreach ($result->errors as $error) {
			$this->flash->error($error);
		}
	}
}
