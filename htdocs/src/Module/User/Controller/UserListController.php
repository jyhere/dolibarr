<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Controller;

use Dolibarr\Core\Config;
use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\Response;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\HookResult;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Routing\Route;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Core\Translator;
use Dolibarr\Core\View\ViewModel;
use Dolibarr\Module\User\Entity\UserView;
use Dolibarr\Module\User\Entity\Visibility;
use Dolibarr\Module\User\Repository\UserListRow;
use Dolibarr\Module\User\Repository\UserPage;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Repository\UserSearch;
use Dolibarr\Module\User\Security\UserPermissionChecker;
use Dolibarr\Module\User\Service\QuotaReachedException;
use Dolibarr\Module\User\Service\UserService;
use Dolibarr\Module\User\Service\ValidationException;
use Dolibarr\Module\User\View\UserListChrome;

/**
 * The user list, htdocs/user/list.php: the criteria, the selected columns, the mass actions disable and
 * reactivate (l.318-372) and the view (l.577-1484). The form posts to the list itself, as list.php.
 *
 * Differences with list.php, on purpose:
 * - the writes (selected columns, mass actions) only run on a POST (main.inc.php checked its token), then
 *   redirect to the list with the same criteria ($param) instead of rendering it in the same request;
 * - the tag and supervisor mass actions (preaffecttag, presetsupervisor, then affecttag and setsupervisor of
 *   actions_massactions.inc.php) stay on list.php: the request is forwarded there with the selection;
 * - the access check comes before the completeArrayFields hook (list.php runs it first, l.165 then l.241).
 */
final class UserListController
{
	/** list.php l.85, for the user list and the employee list alike */
	private const HOOK_CONTEXTS = ['userlist'];

	/** list.php l.49 */
	private const DOMAINS = ['users', 'companies', 'hrm', 'salaries'];

	private const LEGACY_MASS_ACTIONS = ['preaffecttag', 'presetsupervisor'];

	public function __construct(
		private readonly UserRepository $repo,
		private readonly UserPermissionChecker $perms,
		private readonly CurrentUser $me,
		private readonly LegacyHooks $hooks,
		private readonly Translator $translator,
		private readonly UserListChrome $chrome,
		private readonly FlashBag $flash,
		private readonly UserService $service,
		private readonly Config $config,
		private readonly UrlGenerator $urls
	) {
	}

	/**
	 * @throws AccessDeniedException When the current user may not list users (list.php l.241-249)
	 */
	#[Route('/user', methods: ['GET', 'POST'], name: 'user_list')]
	public function list(Request $request): ViewModel|Response
	{
		foreach ([...self::DOMAINS, 'errors'] as $domain) {
			$this->translator->load($domain);
		}
		$employeeList = $this->param($request, 'contextpage', 'aZ') === 'employeelist';
		$search = UserSearch::fromRequest($request, $this->config, $employeeList, $this->chrome->listLimit(), $this->chrome->extraSortable());

		// l.241-249
		if ($this->config->isModuleEnabled('salaries') && $employeeList && $search->filter('search_employee') === '1') {
			if (!$this->me->hasRight('salaries', 'read')) {
				throw new AccessDeniedException();
			}
		} elseif (!$this->perms->canList()) {
			throw new AccessDeniedException();
		}

		$this->hooks->init(self::HOOK_CONTEXTS);
		// l.51; main.inc.php removes action on an invalid token: 'view' then
		$action = $this->param($request, 'action', 'aZ09');
		$action = $action !== '' ? $action : 'view';
		$searchOptions = $this->chrome->searchOptions();

		// l.137-176
		$arrayfields = $this->chrome->arrayFields($employeeList, $this->perms->canReadHr(), $this->me->isAdmin(), $this->me->hasRight('salaries', 'readall'));
		$this->hook('completeArrayFields', ['arrayfields' => &$arrayfields], $action);
		$arrayfields = $this->chrome->completeArrayFields($arrayfields);
		// Only the columns enabled for the viewer may sort the list (no sort on a hidden salary or api key)
		$sortable = [];
		foreach ($arrayfields as $key => $field) {
			if (!array_key_exists('enabled', $field) || !empty($field['enabled'])) {
				$sortable[] = (string) $key;
			}
		}
		$search = $search->withSortableColumns($sortable);

		// l.258-264
		$massaction = $this->param($request, 'massaction', 'alpha');
		if ($this->param($request, 'cancel', 'alpha') !== '') {
			$action = 'list';
			$massaction = '';
		}
		if ($this->param($request, 'confirmmassaction', 'alpha') === '' && $massaction !== 'presend' && $massaction !== 'confirm_presend') {
			$massaction = '';
		}
		/** @var list<int> $toselect */
		$toselect = array_values(array_filter(array_map('intval', (array) $request->get('toselect', 'array:int', [])), static fn (int $id): bool => $id > 0));

		// l.266-271
		$doActions = $this->hook('doActions', ['arrayfields' => &$arrayfields], $action);
		$this->flashHookErrors($doActions);

		$listUrl = $this->urls->generate('user_list');
		$invalidToken = $request->get('errorcode', 'aZ09') === 'InvalidToken';
		if ($doActions->code === 0 && !$invalidToken) {
			// l.274: actions_changeselectedfields.inc.php
			$varpage = $search->contextpage;
			if ($request->isPost() && $this->param($request, 'formfilteraction') === 'listafterchangingselectedfields') {
				try {
					$this->service->saveListFields($varpage, $this->param($request, 'selectedfields'));
				} catch (ValidationException $e) {
					$this->flashErrors($e->messages);
				}

				return new RedirectResponse($this->listUrl($search, $searchOptions));
			}
			// l.277-308
			$removeFilter = $this->param($request, 'button_removefilter_x', 'alpha') !== '' || $this->param($request, 'button_removefilter', 'alpha') !== '';
			if ($removeFilter) {
				$search = $search->reset();
				$searchOptions = [];
				$toselect = [];
			}
			if ($removeFilter || $this->param($request, 'button_search_x', 'alpha') !== '' || $this->param($request, 'button_search', 'alpha') !== '') {
				$massaction = '';
			}
			// l.318-372
			if ($request->isPost() && ($massaction === 'disable' || $massaction === 'reactivate') && $this->perms->canUseMassActions()) {
				$this->massStatus($toselect, $massaction === 'reactivate');

				return new RedirectResponse($this->listUrl($search, $searchOptions));
			}
			// l.375: actions_massactions.inc.php, kept on list.php
			if (in_array($massaction, self::LEGACY_MASS_ACTIONS, true)) {
				return new RedirectResponse($this->legacyMassActionUrl($massaction, $toselect, $search, $searchOptions));
			}
		}

		// l.770-771, before the query: the saved selection decides the columns (and the api_key column)
		$htmlOfSelectArray = $this->chrome->selectedFieldsDialog($arrayfields, $search->contextpage);

		// l.398-559
		$noAction = '';
		$efSql = $this->chrome->extraFieldsSql($searchOptions);
		$select = $this->hook('printFieldListSelect', [], $noAction);
		$from = $this->hook('printFieldListFrom', [], $noAction);
		$where = $this->hook('printFieldListWhere', [], $noAction);
		$restrictToChildren = $this->config->isModuleEnabled('salaries') && $employeeList && !$this->me->hasRight('salaries', 'readall');
		$page = $this->repo->search(
			$search,
			$restrictToChildren ? $this->perms->subordinateIds() : null,
			$this->me->companyId() > 0 ? $this->me->companyId() : null,
			[
				'select' => $efSql['select'].$select->print,
				// l.429-432: list.php adds the resPrint twice when the hook returns > 0
				'from' => $from->print.($from->code > 0 ? $from->print : ''),
				'where' => $efSql['where'].$where->print,
			],
			!empty($arrayfields['u.api_key']['checked'])
		);
		if ($page->page !== $search->page) {
			$search = $search->firstPage();
		}

		// l.570-575
		if (count($page->items) === 1 && !$page->hasMore && $this->config->string('MAIN_SEARCH_DIRECT_OPEN_IF_ONLY_ONE') !== '' && $search->filter('search_all') !== '' && $search->page === 0) {
			return new RedirectResponse($this->urls->generate('user_show', ['id' => $page->items[0]->user->id]));
		}

		$items = [];
		foreach ($page->items as $row) {
			$items[] = new UserView($row->user, $this->visibility($row), $this->perms->canSeeSalary($row->user));
		}
		$vm = (new ViewModel('user:user_list', []))
			->expose('items', $items)
			->expose('total', $page->total)
			->expose('page', $page->page)
			->expose('limit', $page->limit);
		if ($request->wantsJson()) {
			return $vm;
		}

		return $this->view($vm, $request, $search, $page, $page->items, $page->total, $page->hasMore, $arrayfields, $searchOptions, $htmlOfSelectArray, $massaction, $toselect, $employeeList, $doActions, $action);
	}

	/**
	 * The view of list.php l.577-1484.
	 *
	 * @param list<UserListRow>                   $rows
	 * @param array<string, array<string, mixed>> $arrayfields
	 * @param array<string, mixed>                $searchOptions
	 * @param list<int>                           $toselect
	 */
	private function view(ViewModel $vm, Request $request, UserSearch $search, UserPage $page, array $rows, int $total, bool $hasMore, array $arrayfields, array $searchOptions, string $htmlOfSelectArray, string $massaction, array $toselect, bool $employeeList, HookResult $doActions, string $action): ViewModel
	{
		$noAction = '';
		$num = count($rows) + ($hasMore ? 1 : 0);
		$listLimit = $this->chrome->listLimit();
		$param = $search->param($listLimit).$this->chrome->extraFieldsParam($searchOptions);
		$title = $this->translator->trans($employeeList && $search->filter('search_employee') === '1' ? 'Employees' : 'Users');

		// l.655-673
		$canMass = $this->perms->canUseMassActions();
		$hideMass = $request->getInt('nomassaction') !== 0 || in_array($massaction, ['presend', 'predelete', 'preaffecttag', 'presetsupervisor'], true);
		$mass = $this->chrome->massActions($canMass, $hideMass);
		$selectionMode = $mass['html'] !== '' || $massaction !== '';

		$buttons = $this->chrome->viewButtons($param, $search->mode, $employeeList, $this->me->companyId(), $canMass);
		$titleBar = $this->chrome->titleBar($title, $search, $param, $mass['html'], $num, $total, $buttons, $search->page);

		// l.739-768
		$moreForFilter = $this->chrome->moreForFilter($search->filter('search_categ'), $search->filter('search_warehouse'), $this->me->hasRight('categorie', 'read'));
		$preList = $this->hook('printFieldPreListTitle', [], $action);
		$moreForFilter = $preList->code === 0 ? $moreForFilter.$preList->output.$preList->print : $preList->output.$preList->print;

		// l.772-773
		$selectedFields = ($search->mode !== 'kanban' ? $htmlOfSelectArray : '').($mass['count'] > 0 ? $this->chrome->checkAddButtons() : '');

		// l.866-869
		$option = $this->hook('printFieldListOption', ['arrayfields' => $arrayfields], $noAction);

		// l.900-1030
		$titleTotal = ['nbfield' => 0];
		$titlesBefore = $this->chrome->titlesBeforeHook($arrayfields, $param, $search, $selectedFields, $titleTotal);
		$titleHook = $this->hook('printFieldListTitle', ['arrayfields' => $arrayfields, 'param' => $param, 'sortfield' => $search->sortField, 'sortorder' => $search->sortOrder, 'totalarray' => &$titleTotal], $noAction);
		$titlesAfter = $this->chrome->titlesAfterHook($arrayfields, $param, $search, $selectedFields, $titleTotal);

		// l.1044-1458
		$totalarray = ['val' => ['u.salary' => 0], 'nbfield' => 0];
		$rowsView = [];
		foreach ($rows as $i => $row) {
			$rowObject = $this->chrome->legacyRow($row);
			$id = $row->user->id;
			$selected = $selectionMode ? (int) in_array($id, $toselect, true) : -1;
			$before = $this->chrome->rowBeforeHook($row, $rowObject, $i, $arrayfields, $totalarray, $this->canReadHrmData($id), $this->perms->canReadApiKey($row->user));
			$hookRow = $rowObject;
			$value = $this->hooks->execute('printFieldListValue', ['arrayfields' => $arrayfields, 'object' => $rowObject, 'obj' => (object) $row->row, 'i' => $i, 'totalarray' => &$totalarray], $hookRow, $noAction);
			$after = $this->chrome->rowAfterHook($row, $rowObject, $i, $arrayfields, $totalarray);
			$rowsView[] = [
				'user' => $row->user,
				'countryLabel' => $row->countryLabel,
				'importKey' => is_scalar($row->row['import_key'] ?? null) ? (string) $row->row['import_key'] : '',
				'selected' => $selected,
				'kanbanHtml' => $search->mode === 'kanban' ? $this->chrome->kanban($rowObject, $selected) : '',
				'cells' => $before + $after,
				'hookHtml' => $value->output.$value->print,
			];
		}

		// l.1464-1472
		$colspan = 1;
		foreach ($arrayfields as $val) {
			if (!empty($val['checked'])) {
				$colspan++;
			}
		}

		$footer = $this->hook('printFieldListFooter', ['arrayfields' => $arrayfields, 'sql' => $page->sql], $action);
		$show = [];
		foreach ($arrayfields as $key => $val) {
			$show[$key] = !empty($val['checked']);
		}

		return $vm
			->with('outputBeforeHeader', $doActions->output)
			->with('title', $title)
			->with('helpUrl', 'EN:Module_Users|FR:Module_Utilisateurs|ES:M&oacute;dulo_Usuarios|DE:Modul_Benutzer')
			->with('bodyCss', 'bodyforlist mod-user page-list')
			->with('formAction', $this->urls->generate('user_list'))
			->with('userSearch', $search)
			->with('search', $search->filters)
			->with('show', $show)
			->with('privacy', $this->config->int('MAIN_ENABLE_LOGINS_PRIVACY') !== 0)
			->with('checkboxLeft', $this->chrome->checkboxLeft())
			->with('titleBarHtml', $titleBar)
			->with('categoryWaysHtml', $this->chrome->categoryWays($request->getInt('catid')))
			->with('searchAllHtml', $search->filter('search_all') !== '' ? $this->chrome->searchAllNotice($search->searchAllFields, $search->filter('search_all')) : '')
			->with('moreForFilterHtml', $moreForFilter)
			->with('widgets', $this->chrome->filterWidgets($search))
			->with('extraFieldsInputsHtml', $this->chrome->extraFieldsSearchInputs($arrayfields, $searchOptions))
			->with('optionHookHtml', $option->output.$option->print)
			->with('titlesBefore', $titlesBefore)
			->with('titleHookHtml', $titleHook->output.$titleHook->print)
			->with('titlesAfter', $titlesAfter)
			->with('kanbanColspan', (int) $titleTotal['nbfield'])
			->with('rows', $rowsView)
			->with('totalsHtml', $this->chrome->totals($totalarray, $num, $search->limit, $search->offset(), $page->sql, $page->sqlFields))
			->with('colspan', $colspan)
			->with('footerHookHtml', $footer->output.$footer->print);
	}

	/**
	 * A hook of the list on its \User (list.php l.82), $action as given.
	 *
	 * @param array<string, mixed> $params
	 */
	private function hook(string $name, array $params, string &$action): HookResult
	{
		$object = $this->chrome->legacyObject();

		return $this->hooks->execute($name, $params, $object, $action);
	}

	/**
	 * l.318-372: refusal of oneself and of an admin (the first refused user stops everything, nothing is written),
	 * then the statuses in one transaction, the quota checked after.
	 *
	 * @param list<int> $ids
	 */
	private function massStatus(array $ids, bool $enable): void
	{
		foreach ($ids as $id) {
			if ($id === $this->me->id()) {
				$this->flash->error($this->translator->trans($enable ? 'CanEnableYourself' : 'CantDisableYourself'));

				return;
			}
			$target = $this->repo->find($id);
			if ($target === null) {
				$this->flash->error($this->translator->trans('ErrorRecordNotFound'));

				return;
			}
			if ($target->admin) {
				$this->flash->error($this->translator->transHtml($enable ? 'CantEnableAnAdminUserWithMassActions' : 'CantDisableAnAdminUserWithMassActions', $target->login));

				return;
			}
		}
		try {
			$changed = $this->service->setStatuses($ids, $enable, $this->config->limitUsers());
		} catch (QuotaReachedException) {
			$this->flash->error($this->translator->trans('YourQuotaOfUsersIsReached'));

			return;
		} catch (ValidationException $e) {
			$this->flashErrors($e->messages);

			return;
		} catch (NotFoundException) {
			$this->flash->error($this->translator->trans('ErrorRecordNotFound'));

			return;
		}
		$this->flash->success($this->translator->trans('RecordsModified', (string) $changed));
	}

	/**
	 * The list with the same criteria: $param of list.php l.584-652.
	 *
	 * @param array<string, mixed> $searchOptions
	 */
	private function listUrl(UserSearch $search, array $searchOptions): string
	{
		$param = ltrim($search->param($this->chrome->listLimit()).$this->chrome->extraFieldsParam($searchOptions), '&');
		$params = $search->sortField !== 'u.login' || $search->sortOrder !== 'ASC' ? '&sortfield='.urlencode($search->sortField).'&sortorder='.urlencode($search->sortOrder) : '';
		if ($search->page > 0) {
			$params .= '&page='.$search->page;
		}
		$query = ltrim($param.$params, '&');

		return $this->urls->generate('user_list').($query !== '' ? '?'.$query : '');
	}

	/**
	 * The tag and supervisor mass actions on list.php, which shows their confirmation and runs them
	 * (actions_massactions.inc.php): the selection and the criteria in the query string.
	 *
	 * @param list<int>            $toselect
	 * @param array<string, mixed> $searchOptions
	 */
	private function legacyMassActionUrl(string $massaction, array $toselect, UserSearch $search, array $searchOptions): string
	{
		$query = 'massaction='.urlencode($massaction).'&confirmmassaction=1';
		foreach ($toselect as $id) {
			$query .= '&toselect%5B%5D='.$id;
		}

		return $this->config->urlRoot().'/user/list.php?'.$query.$search->param($this->chrome->listLimit()).$this->chrome->extraFieldsParam($searchOptions);
	}

	/**
	 * list.php l.1082-1087 ($canreadhrmdata).
	 */
	private function canReadHrmData(int $id): bool
	{
		$salaries = $this->config->isModuleEnabled('salaries');

		return ($salaries && $this->me->hasRight('salaries', 'read') && in_array($id, $this->perms->subordinateIds(), true))
			|| ($salaries && $this->me->hasRight('salaries', 'readall'))
			|| ($this->config->isModuleEnabled('hrm') && $this->me->hasRight('hrm', 'employee', 'read'));
	}

	private function visibility(UserListRow $row): Visibility
	{
		return match (true) {
			$this->me->isAdmin() => Visibility::Admin,
			$this->me->id() === $row->user->id => Visibility::SameUser,
			default => Visibility::Public,
		};
	}


	private function param(Request $request, string $name, string $filter = 'alphanohtml'): string
	{
		$value = $request->get($name, $filter);

		return is_scalar($value) ? (string) $value : '';
	}

	/**
	 * @param list<string> $messages
	 */
	private function flashErrors(array $messages): void
	{
		foreach ($messages as $message) {
			$this->flash->error($message);
		}
	}

	private function flashHookErrors(HookResult $result): void
	{
		if ($result->failed()) {
			$this->flashErrors($result->errors);
		}
	}
}
