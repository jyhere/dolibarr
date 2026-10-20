<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Controller;

use Dolibarr\Core\Config;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\Response;
use Dolibarr\Core\Http\SafeRedirect;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\HookResult;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Routing\Route;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Core\Translator;
use Dolibarr\Core\View\Confirm;
use Dolibarr\Core\View\ViewModel;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Entity\UserView;
use Dolibarr\Module\User\Entity\Visibility;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\UserInput;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\Service\UserValidator;
use Dolibarr\Module\User\Service\ValidationException;
use Dolibarr\Module\User\View\UserCardChrome;
use Dolibarr\Module\User\View\UserEditRights;
use Dolibarr\Module\User\View\UserFormChrome;

final class UserController
{
	private const HOOK_CONTEXTS = ['usercard', 'globalcard'];

	/**
	 * The dialogs of card.php l.1697-1735 (the clone one excepted): route of the confirmed action, title,
	 * question (with the login) and confirmed action.
	 */
	private const DIALOGS = [
		'password' => ['user_password_post', 'ReinitPassword', 'ConfirmReinitPassword', 'confirm_password'],
		'passwordsend' => ['user_password_post', 'SendNewPassword', 'ConfirmSendNewPassword', 'confirm_passwordsend'],
		'disable' => ['user_status', 'DisableAUser', 'ConfirmDisableUser', 'confirm_disable'],
		'enable' => ['user_status', 'EnableAUser', 'ConfirmEnableUser', 'confirm_enable'],
		'delete' => ['user_delete', 'DeleteAUser', 'ConfirmDeleteUser', 'confirm_delete'],
	];

	/** card.php l.79 */
	private const DOMAINS = ['users', 'companies', 'ldap', 'admin', 'hrm', 'stocks', 'other'];

	public function __construct(
		private readonly UserRepository $repo,
		private readonly UserPermissionChecker $perms,
		private readonly CurrentUser $me,
		private readonly LegacyHooks $hooks,
		private readonly LegacyObjectLoader $legacy,
		private readonly Translator $t,
		private readonly UserCardChrome $chrome,
		private readonly FlashBag $flash,
		private readonly UserService $service,
		private readonly UserValidator $validator,
		private readonly UserFormChrome $formChrome,
		private readonly Config $config,
		private readonly UrlGenerator $urls,
		private readonly Confirm $confirm
	) {
	}

	/**
	 * Read-only user card, the view mode of htdocs/user/card.php. With ?confirm=disable|enable|delete
	 * (or password|passwordsend, card.php's action=disable...), the card shows that confirmation dialog,
	 * posting to the action route.
	 *
	 * @throws NotFoundException     When the user does not exist in the entity
	 * @throws AccessDeniedException When the current user may not read it
	 */
	#[Route('/user/{id}', name: 'user_show', requirements: ['id' => '\d+'])]
	public function show(Request $r, int $id): ViewModel
	{
		$dialog = $this->param($r, 'confirm', 'aZ09');

		return $this->card($r, $id, array_key_exists($dialog, self::DIALOGS) ? $dialog : '');
	}

	/**
	 * The card with the dialog of card.php's action=password, or action=passwordsend with ?mode=send.
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 */
	#[Route('/user/{id}/password', methods: ['GET'], name: 'user_password', requirements: ['id' => '\d+'])]
	public function passwordForm(Request $r, int $id): ViewModel
	{
		return $this->card($r, $id, $this->param($r, 'mode', 'alpha') === 'send' ? 'passwordsend' : 'password');
	}

	/**
	 * Actions confirm_disable (card.php l.215-228) and confirm_enable (l.230-250), then the card.
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 */
	#[Route('/user/{id}/status', methods: ['POST'], name: 'user_status', requirements: ['id' => '\d+'])]
	public function setStatus(Request $r, int $id): RedirectResponse
	{
		$user = $this->prepareAction($id);
		$action = $this->standardAction($r, $user, true);

		if ($action === 'confirm_disable') {
			if ($this->perms->canDisable($user)) {
				$this->writeOrFlash(fn () => $this->service->setStatus($id, false));
			} elseif ($this->perms->canEnable($user) && !$this->perms->canDeleteAdminTarget($user)) {
				// Allowed, not oneself, refused as an admin by a non-admin (card.php l.218-221)
				$this->flash->error($this->t->trans('OnlyAdminUsersCanDisableAdminUsers'));
			}
		} elseif ($action === 'confirm_enable' && $this->perms->canEnable($user)) {
			// card.php l.235-241
			$limit = $this->config->limitUsers();
			if ($limit > 0 && $this->repo->countForQuota() >= $limit) {
				$this->flash->error($this->t->trans('YourQuotaOfUsersIsReached'));
			} else {
				$this->writeOrFlash(fn () => $this->service->setStatus($id, true));
			}
		}

		// card.php l.226 and l.248: the card, without message
		return new RedirectResponse($this->urls->generate('user_show', ['id' => $id]));
	}

	/**
	 * Action confirm_delete (card.php l.252-285): the list on success, else the card.
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 */
	#[Route('/user/{id}/delete', methods: ['POST'], name: 'user_delete', requirements: ['id' => '\d+'])]
	public function delete(Request $r, int $id): RedirectResponse
	{
		$user = $this->prepareAction($id);

		if ($this->standardAction($r, $user, true) === 'confirm_delete') {
			if ($this->perms->canDelete($user)) {
				if ($this->writeOrFlash(fn () => $this->service->delete($id))) {
					$this->flash->success($this->t->trans('RecordDeleted'));

					return new RedirectResponse($this->config->urlRoot().'/user/list.php?restore_lastsearch_values=1');
				}
			} elseif ($this->perms->canEnable($user)) {
				// Allowed, not oneself: refused by an admin rule (card.php l.261-267)
				$this->flash->error($this->t->trans($this->perms->canDeleteAdminTarget($user) ? 'OnlySuperAdminUsersCanDeleteSuperAdminUsers' : 'OnlyAdminUsersCanDeleteAdminUsers'));
			}
		}

		return new RedirectResponse($this->urls->generate('user_show', ['id' => $id]));
	}

	/**
	 * Actions confirm_password and confirm_passwordsend (card.php l.776-797): a new generated password,
	 * shown or sent by email, then the card (card.php renders it without redirection).
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 */
	#[Route('/user/{id}/password', methods: ['POST'], name: 'user_password_post', requirements: ['id' => '\d+'])]
	public function setPassword(Request $r, int $id): RedirectResponse
	{
		$user = $this->prepareAction($id);
		$action = $this->standardAction($r, $user, true);

		if ($action === 'confirm_password' && $this->perms->canSetPassword($user)) {
			$clear = '';
			if ($this->writeOrFlash(function () use ($id, &$clear): void {
				$clear = $this->service->resetPassword($id, false);
			})) {
				$this->flash->warning($this->htmlTrans('PasswordChangedTo', $clear));
			}
		} elseif ($action === 'confirm_passwordsend' && $this->perms->canSendPassword($user)) {
			if ($this->writeOrFlash(fn () => $this->service->resetPassword($id, true))) {
				$this->flash->success($this->htmlTrans('PasswordChangedAndSentTo', (string) $user->email));
			}
		}

		return new RedirectResponse($this->urls->generate('user_show', ['id' => $id]));
	}

	/**
	 * Actions addgroup and removegroup (card.php l.455-476), then the card (card.php renders it without
	 * redirection).
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 */
	#[Route('/user/{id}/groups', methods: ['POST'], name: 'user_groups', requirements: ['id' => '\d+'])]
	public function groups(Request $r, int $id): RedirectResponse
	{
		$user = $this->prepareAction($id);
		$action = $this->standardAction($r, $user, false);
		$group = $r->getInt('group');

		if (($action === 'addgroup' || $action === 'removegroup') && $this->perms->canEditGroups($user) && $group > 0) {
			try {
				$this->writeOrFlash(fn () => $action === 'addgroup' ? $this->service->addToGroup($id, $group) : $this->service->removeFromGroup($id, $group));
			} catch (NotFoundException) {
				$this->flash->error($this->t->trans('ErrorRecordNotFound'));
			}
		}

		return new RedirectResponse($this->urls->generate('user_show', ['id' => $id]));
	}

	/**
	 * The card, with the dialog $dialog (a key of DIALOGS) or none ('').
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 */
	private function card(Request $r, int $id, string $dialog): ViewModel
	{
		$user = $this->findReadable($id);

		// card.php l.79
		foreach (self::DOMAINS as $domain) {
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
		// card.php's $action: the dialog shown (action=disable...), else none
		$action = $dialog;
		if ($this->hooks->hasListenersFor(self::HOOK_CONTEXTS)) {
			$object = $legacyUser;
			// card.php l.185 ($parameters, still current when extrafields_view.tpl.php runs formObjectOptions, l.2004);
			// $socid is the one of the current user (l.124-127), $group is GETPOSTINT('group') (l.86).
			$parameters = [
				'id' => $id,
				'socid' => $this->me->companyId(),
				'group' => $r->getInt('group'),
				'caneditgroup' => $this->perms->canEditGroups($user),
			];

			// card.php l.185-189, the Actions section. A hook returning > 0 replaces the standard actions:
			// a GET show runs none, so the card is rendered in both cases. What it echoes, card.php prints
			// before llxHeader().
			$doActions = $this->hooks->execute('doActions', $parameters, $object, $action);
			$this->flashHookErrors($doActions);
			$outputBeforeHeader = $doActions->output;

			// extrafields_view.tpl.php l.72-76: echoed output, then resPrint, always
			$options = $this->hooks->execute('formObjectOptions', $parameters, $object, $action);
			$this->flashHookErrors($options);
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

		$confirmDialog = '';
		if ($dialog !== '') {
			// card.php l.1697-1719 pass $selectedchoice = 0: "No" is preselected
			[$route, $title, $question, $confirmAction] = self::DIALOGS[$dialog];
			$confirmDialog = $this->confirm->render($this->urls->generate($route, ['id' => $id]), $this->t->trans($title), $this->htmlTrans($question, $user->login), $confirmAction, [], 'no');
		}

		return (new ViewModel('user:user_show', [
			'outputBeforeHeader' => $outputBeforeHeader,
			'confirmDialogHtml' => $confirmDialog,
			'title' => $personName.' - '.$this->t->trans('Card'),
			'bodyCss' => 'mod-user page-card',
			'canSeeSalary' => $canSeeSalary,
			'card' => $this->chrome->build($legacyUser, $user, $this->perms, $canSeeSalary, $this->repo->countInEntity()),
			'showActions' => $showActions,
			'hookFormObjectOptions' => $hookFormObjectOptions,
			'hookMoreObjectBlock' => $hookMoreObjectBlock,
			'hookMoreActionsButtons' => $hookMoreActionsButtons,
			'groupsHtml' => $this->chrome->groupsBlock($legacyUser, $this->perms->canEditGroups($user), $this->perms->canReadGroups($user), $this->hooks, $action),
			'bottomHtml' => $this->chrome->bottomBlocks($legacyUser, $this->perms->canGenerateDocuments(), $this->perms->canWriteUsers(), $this->me->companyId()),
		]))->expose('user', new UserView($user, $visibility, $canSeeSalary));
	}

	/**
	 * The creation form, the create view of htdocs/user/card.php (l.992-1626).
	 *
	 * @throws AccessDeniedException When the current user may not create users
	 */
	#[Route('/user/new', methods: ['GET'], name: 'user_new')]
	public function createForm(Request $r): ViewModel
	{
		$this->prepareCreate();
		// card.php l.98-102 and l.1278: no date, l.1214: employee checked by default, l.1068: current entity
		$values = new User(0, $this->config->entity(), '', employee: true);

		return $this->form($r, $values, null, false, $this->doActions($values, 'create'));
	}

	/**
	 * Action add of htdocs/user/card.php (l.185-452): validation, creation, redirection.
	 * A failure redisplays the form with the posted values and the messages.
	 *
	 * @throws AccessDeniedException When the current user may not create users
	 */
	#[Route('/user/new', methods: ['POST'], name: 'user_new_post')]
	public function create(Request $r): Response|ViewModel
	{
		$this->prepareCreate();
		$backtopage = $this->param($r, 'backtopage');
		$backtopageforcancel = $this->param($r, 'backtopageforcancel');
		$urlRoot = $this->config->urlRoot();

		// main.inc.php refused the CSRF token (l.456-478): $_POST is emptied, $_GET['action'] unset and its warning
		// set. card.php then runs doActions with an empty action and no standard action: the same here, nothing
		// is written and the form is redisplayed.
		$blank = new User(0, $this->config->entity(), '', employee: true);
		if ($r->get('errorcode', 'aZ09') === 'InvalidToken') {
			return $this->form($r, $blank, null, false, $this->doActions($blank, ''));
		}

		// card.php l.185-191: doActions first; the standard actions (cancel included) only run when it returns 0.
		// A hook that fails (< 0, its errors flashed) or replaces the action (> 0) vetoes the creation.
		$doActions = $this->doActions($blank, 'add');
		if ($doActions->code !== 0) {
			$in = UserInput::fromRequest($r, $this->perms, null, $this->config);

			return $this->form($r, $in->toEntity(null, $this->entityFor($in)), $in->password, $in->forcePasswordChange, $doActions);
		}

		// card.php l.192-212: cancel goes to backtopageforcancel, else backtopage, else the list
		// (a backtopage waiting for the new id is no target on a cancel)
		if ($this->param($r, 'cancel', 'alpha') !== '') {
			$candidate = $backtopageforcancel !== ''
				? $backtopageforcancel
				: ($backtopage !== '' && !str_contains($backtopage, '__ID__') ? $backtopage : null);

			return new RedirectResponse(SafeRedirect::relativePath($candidate, $urlRoot) ?? $urlRoot.'/user/list.php');
		}

		$in = UserInput::fromRequest($r, $this->perms, null, $this->config);
		$values = $in->toEntity(null, $this->entityFor($in));

		$errors = $this->validator->validate($in, true, $this->repo->countForQuota());
		if ($errors !== []) {
			// setOptionalsFromPost() has already queued its own message: not flashed twice
			$this->flashErrors($errors, $in->extraFieldsError);

			return $this->form($r, $values, $in->password, $in->forcePasswordChange, $doActions);
		}

		try {
			// card.php l.424-428: the tags only with the category module
			$created = $this->service->create($values, $in->password, $this->config->isModuleEnabled('category') ? $in->categoryIds : []);
		} catch (ValidationException $e) {
			$this->flashErrors($e->messages);

			return $this->form($r, $values, $in->password, $in->forcePasswordChange, $doActions);
		}

		// card.php l.430-436: no message, backtopage with the new id, else the card
		$target = $backtopage !== '' ? SafeRedirect::relativePath(str_replace('__ID__', (string) $created->id, $backtopage), $urlRoot) : null;

		return new RedirectResponse($target ?? $this->urls->generate('user_show', ['id' => $created->id]));
	}

	/**
	 * The edit form, the edit view of htdocs/user/card.php (l.2498-3309). An actor who may only set the
	 * password (card.php l.165) gets the fields read-only and the password input.
	 *
	 * @throws NotFoundException     When the user does not exist in the entity
	 * @throws AccessDeniedException When the current user may neither edit it nor set its password
	 */
	#[Route('/user/{id}/edit', methods: ['GET'], name: 'user_edit', requirements: ['id' => '\d+'])]
	public function editForm(Request $r, int $id): ViewModel
	{
		$existing = $this->prepareEdit($id);

		return $this->editView($existing, $existing, $existing->forcePasswordChange, false, $this->doActionsEdit($r, $existing, 'edit'));
	}

	/**
	 * Action update of htdocs/user/card.php (l.478-773), then a redirection to the card (card.php renders
	 * the card again without one: a redirect-after-POST here). A failure redisplays the form with the posted
	 * values and the messages.
	 *
	 * @throws NotFoundException     When the user does not exist in the entity
	 * @throws AccessDeniedException When the current user may neither edit it nor set its password
	 */
	#[Route('/user/{id}/edit', methods: ['POST'], name: 'user_edit_post', requirements: ['id' => '\d+'])]
	public function update(Request $r, int $id): Response|ViewModel
	{
		$existing = $this->prepareEdit($id);
		$card = $this->urls->generate('user_show', ['id' => $id]);

		// Invalid CSRF token (main.inc.php l.456-478): doActions with an empty action, nothing written
		if ($r->get('errorcode', 'aZ09') === 'InvalidToken') {
			return $this->editView($existing, $existing, $existing->forcePasswordChange, false, $this->doActionsEdit($r, $existing, ''));
		}

		// card.php l.185-191: the standard actions only when doActions returns 0
		$doActions = $this->doActionsEdit($r, $existing, 'update');
		if ($doActions->code !== 0) {
			$in = UserInput::fromRequest($r, $this->perms, $existing, $this->config);

			return $this->editView($existing, $in->toEntity($existing, $this->entityForUpdate($in, $existing)), $in->forcePasswordChange, true, $doActions);
		}

		// card.php l.203-212: backtopageforcancel, else backtopage, else the card
		if ($this->param($r, 'cancel', 'alpha') !== '') {
			$backtopageforcancel = $this->param($r, 'backtopageforcancel');
			$candidate = $backtopageforcancel !== '' ? $backtopageforcancel : $this->param($r, 'backtopage');

			return new RedirectResponse(SafeRedirect::relativePath($candidate === '' ? null : $candidate, $this->config->urlRoot()) ?? $card);
		}

		if (!$this->perms->canEdit($existing)) {
			return $this->updatePasswordOnly($r, $existing, $doActions) ?? new RedirectResponse($card);
		}

		$in = UserInput::fromRequest($r, $this->perms, $existing, $this->config);
		$values = $in->toEntity($existing, $this->entityForUpdate($in, $existing));

		$errors = $this->validator->validate($in, false, 0);
		if ($errors !== []) {
			// setOptionalsFromPost() has already queued its own message: not flashed twice
			$this->flashErrors($errors, $in->extraFieldsError);

			return $this->editView($existing, $values, $in->forcePasswordChange, true, $doActions);
		}

		// fromRequest() reads the password only with canSetPassword(); the service sets it when it changed
		try {
			$result = $this->service->update($values, $in->categoryIds, $in->contactId, $in->companyId, $in->password);
		} catch (ValidationException $e) {
			$this->flashErrors($e->messages);

			return $this->editView($existing, $values, $in->forcePasswordChange, true, $doActions);
		}

		$after = $result->user;
		// card.php l.665-668: the chosen contact has no third party, the posted one is dropped
		if ($result->contactCompanyMismatch) {
			$this->flash->warning($this->t->trans('WarningUserDifferentContactSocid'));
		}
		// card.php l.736-750
		$this->flash->success($this->t->trans('UserModified'));
		if ($this->me->login() === $existing->login && $existing->login !== $after->login) {
			$this->flash->warning($this->t->transnoentities('WarningYourLoginWasModifiedPleaseLogin'));
		}
		if ($result->passwordChanged && $after->login === $this->me->login()) {
			$this->flash->warning($this->t->transnoentities('WarningYourPasswordWasModifiedPleaseLogin'));
		}

		return new RedirectResponse($card);
	}

	/**
	 * card.php l.757-772: only the password, set when one is typed (even the current one), no message.
	 *
	 * @return ViewModel|null The form again when the password is refused, null on success
	 */
	private function updatePasswordOnly(Request $r, User $existing, HookResult $doActions): ?ViewModel
	{
		$clear = $r->getPost('password', 'password', '');
		if (!is_string($clear) || $clear === '') {
			return null;
		}
		try {
			$this->service->setPasswordFromForm($existing->id, $clear);
		} catch (ValidationException $e) {
			$this->flashErrors($e->messages);

			return $this->editView($existing, $existing, $existing->forcePasswordChange, true, $doActions);
		}

		return null;
	}

	/**
	 * card.php l.79, l.119 and l.152-154 (read access): the user, the domains and the hooks of an action
	 * of the card. The right of each action is checked by the action, as card.php does.
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 */
	private function prepareAction(int $id): User
	{
		$user = $this->findReadable($id);
		foreach ([...self::DOMAINS, 'errors'] as $domain) {
			$this->t->load($domain);
		}
		$this->hooks->init(self::HOOK_CONTEXTS);

		return $user;
	}

	/**
	 * card.php l.152-154: the user, readable by the current one (a user always reads his own card, l.147).
	 *
	 * @throws NotFoundException     When the user does not exist in the entity
	 * @throws AccessDeniedException When the current user may not read it
	 */
	private function findReadable(int $id): User
	{
		$user = $this->repo->find($id);
		if ($user === null) {
			throw new NotFoundException('User '.$id.' not found');
		}
		if (!$this->perms->canRead($user)) {
			throw new AccessDeniedException();
		}

		return $user;
	}

	/**
	 * The standard action card.php would run, '' for none. On an invalid CSRF token (main.inc.php l.456-478,
	 * its warning already set), doActions gets an empty action and nothing runs. Otherwise doActions gets the
	 * posted action (card.php l.185-191) and only a 0 lets the standard action run; a confirmed action also
	 * needs confirm=yes.
	 */
	private function standardAction(Request $r, User $user, bool $confirmed): string
	{
		if ($r->get('errorcode', 'aZ09') === 'InvalidToken') {
			$this->doActionsEdit($r, $user, '');

			return '';
		}
		$action = $this->param($r, 'action', 'aZ09');
		if ($this->doActionsEdit($r, $user, $action)->code !== 0) {
			return '';
		}

		return !$confirmed || $this->param($r, 'confirm', 'alpha') === 'yes' ? $action : '';
	}

	/**
	 * A translation going into HTML (a flash message, a legacy dialog) with its arguments escaped, as the
	 * legacy \Translate::trans() of card.php escapes them; the tags of the translation itself are kept.
	 */
	private function htmlTrans(string $key, string ...$args): string
	{
		return $this->t->trans($key, ...array_map(static fn (string $a): string => htmlspecialchars($a, ENT_COMPAT, 'UTF-8'), $args));
	}

	/**
	 * Runs a write, its refusal flashed as errors.
	 *
	 * @param callable(): mixed $write
	 * @return bool Whether the write succeeded
	 */
	private function writeOrFlash(callable $write): bool
	{
		try {
			$write();
		} catch (ValidationException $e) {
			$this->flashErrors($e->messages);

			return false;
		}

		return true;
	}

	/**
	 * card.php l.79, l.119, l.152-154 (read access) and l.478 (edit or password right): the user, the domains
	 * and the hooks of the edit view.
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 */
	private function prepareEdit(int $id): User
	{
		$user = $this->findReadable($id);
		if (!$this->perms->canEdit($user) && !$this->perms->canSetPassword($user)) {
			throw new AccessDeniedException();
		}
		foreach ([...self::DOMAINS, 'errors'] as $domain) {
			$this->t->load($domain);
		}
		$this->hooks->init(self::HOOK_CONTEXTS);

		return $user;
	}

	/**
	 * card.php l.185-189 with the parameters of an existing user (l.82-86, l.122-127).
	 */
	private function doActionsEdit(Request $r, User $existing, string $action): HookResult
	{
		$object = $this->formChrome->legacyObject($existing);
		$result = $this->hooks->execute('doActions', [
			'id' => $existing->id,
			'socid' => $this->me->companyId(),
			'group' => $r->getInt('group'),
			'caneditgroup' => $this->perms->canEditGroups($existing),
		], $object, $action);
		$this->flashHookErrors($result);

		return $result;
	}

	/**
	 * card.php l.595-606: the entity only changes with multicompany, from an admin without entity.
	 */
	private function entityForUpdate(UserInput $in, User $existing): int
	{
		if (!$this->config->isModuleEnabled('multicompany') || !$this->me->isAdmin() || $this->me->entity() !== 0) {
			return $existing->entity;
		}
		if ($in->superadmin) {
			return 0;
		}
		if ($this->config->string('MULTICOMPANY_TRANSVERSE_MODE') !== '') {
			return 1;
		}

		return $in->entity ?? $existing->entity;
	}

	/**
	 * The edit form of $existing showing $values: the stored ones, or the posted ones on a redisplay.
	 */
	private function editView(User $existing, User $values, bool $forcePasswordChange, bool $redisplay, HookResult $doActions): ViewModel
	{
		$canSeeSalary = $this->perms->canSeeSalary($existing);
		if ($canSeeSalary) {
			$this->t->load('salaries');
		}
		$canEditAdminFlag = $this->perms->canEditAdminFlag($existing);
		$rights = new UserEditRights(
			$this->perms->canEdit($existing),
			$this->perms->canSetPassword($existing),
			$this->perms->canSendPassword($existing),
			$this->perms->canEditLogin($existing, $existing->isLdapManaged),
			$this->me->isAdmin() && !$this->perms->isSelf($existing),
			$this->repo->countInEntity()
		);
		// card.php l.977-979
		$personName = $existing->firstname !== null && $existing->firstname !== '' ? $existing->lastname.', '.$existing->firstname : (string) $existing->lastname;

		return new ViewModel('user:user_form', [
			'outputBeforeHeader' => $doActions->output,
			'title' => $personName.' - '.$this->t->trans('Card'),
			'bodyCss' => 'mod-user page-card',
			'mode' => 'edit',
			'formAction' => $this->urls->generate('user_edit_post', ['id' => $existing->id]),
			'values' => $values,
			'forcePasswordChange' => $forcePasswordChange,
			'backtopage' => '',
			'backtopageforcancel' => '',
			'entity' => $existing->entity,
			'canEdit' => $rights->canEdit,
			'canEditLogin' => $rights->canEditLogin,
			'ldap' => $existing->isLdapManaged,
			'canSeeSalary' => $canSeeSalary,
			'currencySymbol' => $canSeeSalary ? $this->formChrome->currencySymbol() : '',
			// card.php l.3263: the dash only between two posted employment dates
			'employmentDash' => $redisplay && $values->dateEmployment !== null && $values->dateEmploymentEnd !== null,
			'show' => $this->formChrome->visibility($canSeeSalary, $canEditAdminFlag, $rights->nbOfUsers > 1),
			'titleHtml' => '',
			'loginJs' => '',
			'f' => $this->formChrome->fragments($values, 'edit', $canSeeSalary, $canEditAdminFlag, null, $rights),
		]);
	}

	/**
	 * @throws AccessDeniedException
	 */
	private function prepareCreate(): void
	{
		if (!$this->perms->canCreate()) {
			throw new AccessDeniedException();
		}
		// card.php l.79, plus errors (validation messages)
		foreach ([...self::DOMAINS, 'errors'] as $domain) {
			$this->t->load($domain);
		}
		$this->hooks->init(self::HOOK_CONTEXTS);
	}

	/**
	 * A GETPOST() string, '' when absent. backtopage and backtopageforcancel are kept raw for the hidden
	 * fields, and checked by SafeRedirect when used.
	 */
	private function param(Request $r, string $name, string $filter = 'alphanohtml'): string
	{
		$value = $r->get($name, $filter);

		return is_string($value) ? $value : '';
	}

	/**
	 * card.php l.185-189 with the parameters of a creation (l.122-127: $id = 0, $socid of the current user).
	 */
	private function doActions(User $values, string $action): HookResult
	{
		$object = $this->formChrome->legacyObject($values);
		$result = $this->hooks->execute('doActions', [
			'id' => 0,
			'socid' => $this->me->companyId(),
			'group' => 0,
			'caneditgroup' => $this->perms->canEditGroups($values),
		], $object, $action);
		$this->flashHookErrors($result);

		return $result;
	}

	/**
	 * card.php l.392-414: the entity of the new user. The entity and the superadmin flag posted are only
	 * trusted from an admin without entity of his own (the only one shown the selectors).
	 */
	private function entityFor(UserInput $in): int
	{
		$canSetEntity = $this->me->isAdmin() && $this->me->entity() === 0;
		$entity = $canSetEntity ? $in->entity : $this->me->entity();
		if ($this->config->isModuleEnabled('multicompany')) {
			if ($canSetEntity && $in->superadmin) {
				return 0;
			}
			if ($this->config->string('MULTICOMPANY_TRANSVERSE_MODE') !== '') {
				return 1;
			}
		}

		return $entity ?? 1;
	}

	/**
	 * The form with $values: the defaults, or the posted values on a redisplay.
	 */
	private function form(Request $r, User $values, ?string $password, bool $forcePasswordChange, HookResult $doActions): ViewModel
	{
		$canSeeSalary = $this->perms->canSeeSalary($values);
		if ($canSeeSalary) {
			$this->t->load('salaries');
		}
		$canEditAdminFlag = $this->perms->canEditAdminFlag($values);
		// card.php l.981-985
		$title = $this->t->trans($r->getInt('employee') > 0 ? 'NewEmployee' : 'NewUser');

		return new ViewModel('user:user_form', [
			'outputBeforeHeader' => $doActions->output,
			'title' => $title,
			'bodyCss' => 'mod-user page-card',
			'mode' => 'create',
			'formAction' => $this->urls->generate('user_new_post'),
			'values' => $values,
			'forcePasswordChange' => $forcePasswordChange,
			'backtopage' => $this->param($r, 'backtopage'),
			'backtopageforcancel' => $this->param($r, 'backtopageforcancel'),
			'entity' => $this->config->entity(),
			'canSeeSalary' => $canSeeSalary,
			'currencySymbol' => $canSeeSalary ? $this->formChrome->currencySymbol() : '',
			'show' => $this->formChrome->visibility($canSeeSalary, $canEditAdminFlag),
			'titleHtml' => $this->formChrome->title($title),
			'loginJs' => $this->formChrome->loginJs(),
			'f' => $this->formChrome->fragments($values, 'create', $canSeeSalary, $canEditAdminFlag, $password),
		]);
	}

	/**
	 * Each message as an error, except $skip (a message already queued elsewhere).
	 *
	 * @param list<string> $messages
	 */
	private function flashErrors(array $messages, ?string $skip = null): void
	{
		foreach ($messages as $message) {
			if ($message !== $skip) {
				$this->flash->error($message);
			}
		}
	}

	/**
	 * setEventMessages($hookmanager->error, $hookmanager->errors, 'errors') of a failed hook.
	 */
	private function flashHookErrors(HookResult $result): void
	{
		if (!$result->failed()) {
			return;
		}
		foreach ($result->errors as $error) {
			$this->flash->error($error);
		}
	}
}
