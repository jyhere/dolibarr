<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\View;

use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Module\User\Repository\UserListRow;
use Dolibarr\Module\User\Repository\UserSearch;

/**
 * @internal legacy adapter
 *
 * Legacy-rendered fragments of the user list (htdocs/user/list.php, view l.577-1484), and its legacy data:
 * $arrayfields (l.137-176), the extrafields (l.88-90, the extrafields_list_*.tpl.php templates) and the \User
 * hydrated from a row (l.1058-1080). Each fragment comes from the helper list.php calls, with the same
 * arguments (line cited); a helper that prints is captured. The links of \User::getNomUrl() and of the kanban
 * view to user/card.php?id=N become the user_show route.
 *
 * The extrafields templates are included as list.php includes them, in a method scope holding the variables
 * they read ($conf, $langs, $db, $extrafields, $object, $arrayfields...).
 */
final class UserListChrome
{
	private ?\ExtraFields $extrafields = null;

	private ?\User $object = null;

	public function __construct(private readonly LegacyContext $ctx, private readonly UrlGenerator $urls)
	{
		// The libraries main.inc.php's llxHeader() loads, used by the Form helpers (ajax_combobox()...)
		require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/phone.lib.php';
	}

	/**
	 * $conf->liste_limit, as main.inc.php l.1249-1256 sets it.
	 */
	public function listLimit(): int
	{
		$limit = (int) $this->ctx->conf->liste_limit;

		return $limit > 0 ? $limit : 15;
	}

	/**
	 * $conf->main_checkbox_left_column: the checkbox column on the left.
	 */
	public function checkboxLeft(): bool
	{
		return !empty($this->ctx->conf->main_checkbox_left_column);
	}

	/**
	 * l.82: the \User of the hooks, published as the global $object.
	 */
	public function legacyObject(): \User
	{
		if ($this->object === null) {
			$this->object = new \User($this->ctx->db);
			$this->ctx->setPageObject($this->object);
		}

		return $this->object;
	}

	/**
	 * l.88: the extrafields of the users.
	 */
	public function extraFields(): \ExtraFields
	{
		if ($this->extrafields === null) {
			require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
			$this->extrafields = new \ExtraFields($this->ctx->db);
			$this->extrafields->fetch_name_optionals_label('user');
		}

		return $this->extrafields;
	}

	/**
	 * l.90: the extrafields criteria ($search_array_options, keys search_options_<name>).
	 *
	 * @return array<string, mixed>
	 */
	public function searchOptions(): array
	{
		$options = $this->extraFields()->getOptionalsFromPost('user', '', 'search_');

		return is_array($options) ? $options : [];
	}

	/**
	 * The extrafields columns the list may be sorted on (extrafields_list_search_title.tpl.php: ef.<name>,
	 * not the computed ones).
	 *
	 * @return list<string>
	 */
	public function extraSortable(): array
	{
		$attributes = $this->extraFields()->attributes['user'] ?? [];
		$sortable = [];
		foreach (array_keys(is_array($attributes['label'] ?? null) ? $attributes['label'] : []) as $key) {
			if (empty($attributes['computed'][$key])) {
				$sortable[] = 'ef.'.$key;
			}
		}

		return $sortable;
	}

	/**
	 * l.137-162: the columns of the list, before the completeArrayFields hook.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function arrayFields(bool $employeeList, bool $canReadHr, bool $isAdmin, bool $canReadAllSalaries): array
	{
		$multicompany = isModEnabled('multicompany') && !getDolGlobalString('MULTICOMPANY_TRANSVERSE_MODE');
		$hr = isModEnabled('hrm') && $canReadHr;

		return [
			'u.rowid' => ['label' => 'TechnicalID', 'checked' => '-1', 'position' => 5],
			'u.login' => ['label' => 'Login', 'checked' => '1', 'position' => 10],
			'u.lastname' => ['label' => 'Lastname', 'checked' => '1', 'position' => 15],
			'u.firstname' => ['label' => 'Firstname', 'checked' => '1', 'position' => 20],
			'u.entity' => ['label' => 'Entity', 'checked' => '1', 'position' => 50, 'enabled' => (string) (int) $multicompany],
			'u.gender' => ['label' => 'Gender', 'checked' => '0', 'position' => 22],
			'u.employee' => ['label' => 'Employee', 'checked' => ($employeeList ? '1' : '0'), 'position' => 25],
			'u.fk_user' => ['label' => 'HierarchicalResponsible', 'checked' => '1', 'position' => 27, 'csslist' => 'maxwidth150'],
			'u.accountancy_code' => ['label' => 'AccountancyCode', 'checked' => '0', 'position' => 30],
			'u.office_phone' => ['label' => 'PhonePro', 'checked' => '1', 'position' => 31],
			'u.user_mobile' => ['label' => 'PhoneMobile', 'checked' => '1', 'position' => 32],
			'u.email' => ['label' => 'EMail', 'checked' => '1', 'position' => 35],
			'co.label' => ['label' => 'Country', 'checked' => '0', 'position' => 37],
			'u.api_key' => ['label' => 'ApiKey', 'checked' => '0', 'position' => 40, 'enabled' => (string) (int) (isModEnabled('api') && $isAdmin)],
			'u.fk_soc' => ['label' => 'Company', 'checked' => ($employeeList ? '0' : '1'), 'position' => 45],
			'u.ref_employee' => ['label' => 'RefEmployee', 'checked' => '-1', 'position' => 50, 'enabled' => (string) (int) $hr],
			'u.national_registration_number' => ['label' => 'NationalRegistrationNumber', 'checked' => '-1', 'position' => 51, 'enabled' => (string) (int) $hr],
			'u.job' => ['label' => 'PostOrFunction', 'checked' => '-1', 'position' => 60],
			'u.salary' => ['label' => 'Salary', 'checked' => '-1', 'position' => 80, 'enabled' => (string) (int) (isModEnabled('salaries') && $canReadAllSalaries), 'isameasure' => 1],
			'u.thm' => ['label' => 'THM', 'langs' => 'salaries', 'checked' => '-1', 'position' => 82, 'enabled' => '1', 'isameasure' => 1],
			'u.datec' => ['label' => 'DateCreation', 'checked' => '0', 'position' => 500],
			'date_modification' => ['label' => 'DateModificationShort', 'checked' => '0', 'position' => 500],
			'u.import_key' => ['label' => 'ImportId', 'checked' => '-1', 'position' => 800, 'enabled' => '1'],
			'u.statut' => ['label' => 'Status', 'checked' => '1', 'position' => 1000],
		];
	}

	/**
	 * l.167-176, after the completeArrayFields hook: the login dates, the extrafields columns
	 * (extrafields_list_array_fields.tpl.php), sorted by position.
	 *
	 * @param array<string, array<string, mixed>> $arrayfields
	 * @return array<string, array<string, mixed>>
	 */
	public function completeArrayFields(array $arrayfields): array
	{
		if (getDolGlobalInt('MAIN_ENABLE_LOGINS_PRIVACY') == 0) {
			$arrayfields['u.datelastlogin'] = ['label' => 'LastConnexion', 'checked' => '1', 'position' => 100];
			$arrayfields['u.datepreviouslogin'] = ['label' => 'PreviousConnexion', 'checked' => '0', 'position' => 110];
		}
		$conf = $this->ctx->conf;
		$extrafields = $this->extraFields();
		$object = $this->legacyObject();
		include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_array_fields.tpl.php';

		/** @var array<string, array<string, mixed>> $sorted */
		$sorted = dol_sort_array($arrayfields, 'position');

		return $sorted;
	}

	/**
	 * l.770-771: the dialog of the selected fields. Form::multiSelectArrayWithCheckbox() applies the saved
	 * choice MAIN_SELECTEDFIELDS_<varpage> to $arrayfields and drops the disabled columns.
	 *
	 * @param array<string, array<string, mixed>> $arrayfields
	 */
	public function selectedFieldsDialog(array &$arrayfields, string $varpage): string
	{
		$this->ctx->form();

		return (string) \Form::multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage, $this->ctx->conf->main_checkbox_left_column);
	}

	/**
	 * l.408-412 and l.528: the extrafields columns of the SELECT and their criteria
	 * (extrafields_list_search_sql.tpl.php).
	 *
	 * @param array<string, mixed> $searchOptions
	 * @return array{select: string, where: string}
	 */
	public function extraFieldsSql(array $searchOptions): array
	{
		$extrafields = $this->extraFields();
		$select = '';
		$attributes = $extrafields->attributes['user'] ?? [];
		if (!empty($attributes['label']) && is_array($attributes['label'])) {
			foreach ($attributes['label'] as $key => $val) {
				$select .= ($attributes['type'][$key] != 'separate' ? ', ef.'.$key.' as options_'.$key : '');
			}
		}
		$conf = $this->ctx->conf;
		$db = $this->ctx->db;
		$object = $this->legacyObject();
		$search_array_options = $searchOptions;
		$sql = '';
		include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_sql.tpl.php';

		return ['select' => $select, 'where' => $sql];
	}

	/**
	 * l.652: the extrafields part of $param (extrafields_list_search_param.tpl.php).
	 *
	 * @param array<string, mixed> $searchOptions
	 */
	public function extraFieldsParam(array $searchOptions): string
	{
		$conf = $this->ctx->conf;
		$extrafields = $this->extraFields();
		$object = $this->legacyObject();
		$search_array_options = $searchOptions;
		$param = '';
		include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_param.tpl.php';

		return $param;
	}

	/**
	 * l.773: the "check all" box of the selection column.
	 */
	public function checkAddButtons(): string
	{
		return $this->ctx->form()->showCheckAddButtons('checkforselect', 1);
	}

	/**
	 * l.655-673: the mass actions menu. The tag and supervisor entries are kept: the controller forwards them
	 * to list.php.
	 *
	 * @return array{html: string, count: int}
	 */
	public function massActions(bool $canUseMassActions, bool $hide): array
	{
		$langs = $this->ctx->langs;
		$actions = [];
		if ($canUseMassActions) {
			$actions['disable'] = img_picto('', 'close_title', 'class="pictofixedwidth"').$langs->trans('DisableUser');
			$actions['reactivate'] = img_picto('', 'check', 'class="pictofixedwidth"').$langs->trans('Reactivate');
			if (isModEnabled('category')) {
				$actions['preaffecttag'] = img_picto('', 'category', 'class="pictofixedwidth"').$langs->trans('AffectTag');
			}
			$actions['presetsupervisor'] = img_picto('', 'user', 'class="pictofixedwidth"').$langs->trans('SetSupervisor');
		}
		if ($hide) {
			$actions = [];
		}

		return ['html' => (string) $this->ctx->form()->selectMassAction('', $actions), 'count' => count($actions)];
	}

	/**
	 * l.688-698: the view mode buttons and the New user button (to the user_new route).
	 */
	public function viewButtons(string $param, string $mode, bool $employeeList, int $socid, bool $canCreate): string
	{
		$langs = $this->ctx->langs;
		$list = $this->urls->generate('user_list');
		$url = $this->urls->generate('user_new').'?'.($employeeList ? 'search_employee=1&' : '').'leftmenu=';
		if ($socid > 0) {
			$url .= '&socid='.urlencode((string) $socid);
		}
		$withoutMode = (string) preg_replace('/(&|\?)*mode=[^&]+/', '', $param);

		$html = dolGetButtonTitle($langs->trans('ViewList'), '', 'fa fa-bars paddingleft imgforviewmode', $list.'?mode=common'.$withoutMode, '', (($mode === '' || $mode === 'common') ? 2 : 1), ['morecss' => 'reposition']);
		$html .= dolGetButtonTitle($langs->trans('HierarchicView'), '', 'fa fa-stream paddingleft imgforviewmode', DOL_URL_ROOT.'/user/hierarchy.php?mode=hierarchy'.$withoutMode, '', 1, ['morecss' => 'reposition']);
		$html .= dolGetButtonTitle($langs->trans('ViewKanban'), '', 'fa fa-th-list imgforviewmode', $list.'?mode=kanban'.$withoutMode, '', ($mode === 'kanban' ? 2 : 1), ['morecss' => 'reposition']);
		$html .= dolGetButtonTitleSeparator();
		$html .= dolGetButtonTitle($langs->trans('NewUser'), '', 'fa fa-plus-circle', $url, '', (int) $canCreate);

		return $html;
	}

	/**
	 * l.705: the title bar with the pagination, printed by print_barre_liste().
	 *
	 * @param int $total -1 when not counted: no total shown, as list.php's ''
	 */
	public function titleBar(string $title, UserSearch $search, string $param, string $massActionButton, int $num, int $total, string $buttons, int $page): string
	{
		ob_start();
		try {
			print_barre_liste($title, $page, $this->urls->generate('user_list'), $param, $search->sortField, $search->sortOrder, $massActionButton, $num, $total < 0 ? '' : $total, 'user', 0, ' '.$buttons, '', $search->limit, 0, 0, 1);
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * l.716-722: the path of the tag given by catid.
	 */
	public function categoryWays(int $catid): string
	{
		if ($catid <= 0 || !isModEnabled('category')) {
			return '';
		}
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		$category = new \Categorie($this->ctx->db);
		$ways = $category->print_all_ways('auto', 'user/list.php');

		return "<div id='ways'> &gt; ".($ways[0] ?? '')."<br>\n</div><br>";
	}

	/**
	 * l.724-732: what search_all looks into.
	 *
	 * @param array<string, string> $fields
	 */
	public function searchAllNotice(array $fields, string $searchAll): string
	{
		$langs = $this->ctx->langs;
		$setup = '';
		$labels = [];
		foreach ($fields as $key => $val) {
			$labels[] = $langs->trans($val);
			$setup .= $key.'='.$val.';';
		}

		return '<!-- Search done like if USER_QUICKSEARCH_ON_FIELDS = '.$setup.' -->'."\n"
			.'<div class="divsearchfieldfilter">'.$langs->trans('FilterOnInto', $searchAll).implode(', ', $labels).'</div>';
	}

	/**
	 * l.739-754: the tag and warehouse filters above the table (the hook part is the controller's).
	 */
	public function moreForFilter(string $categ, string $warehouse, bool $canReadCategories): string
	{
		$langs = $this->ctx->langs;
		$html = '';
		if (isModEnabled('category') && $canReadCategories) {
			require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
			require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
			$formother = new \FormOther($this->ctx->db);
			$html .= '<div class="divsearchfield">';
			$html .= img_picto($langs->trans('Category'), 'category', 'class="pictofixedwidth"').$formother->select_categories(\Categorie::TYPE_USER, $categ, 'search_categ', 1, $langs->trans('Category'));
			$html .= '</div>';
		}
		if (isModEnabled('stock') && getDolGlobalString('MAIN_DEFAULT_WAREHOUSE_USER')) {
			require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
			$formproduct = new \FormProduct($this->ctx->db);
			$html .= '<div class="divsearchfield">';
			$html .= img_picto($langs->trans('Warehouse'), 'stock', 'class="pictofixedwidth"').$formproduct->selectWarehouses($warehouse, 'search_warehouse', '', 1, 0, 0, $langs->trans('Warehouse'));
			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * l.783-897: the selectors of the filters row and its buttons.
	 *
	 * @return array<string, string>
	 */
	public function filterWidgets(UserSearch $search): array
	{
		$langs = $this->ctx->langs;
		$form = $this->ctx->form();

		return [
			'buttonsLeft' => $form->showFilterButtons('left'),
			'buttonsRight' => $form->showFilterButtons(),
			'gender' => $form->selectarray('search_gender', ['man' => $langs->trans('Genderman'), 'woman' => $langs->trans('Genderwoman'), 'other' => $langs->trans('Genderother')], $search->filter('search_gender'), 1),
			'employee' => (string) $form->selectyesno('search_employee', $search->filter('search_employee'), 1, false, 1),
			'supervisor' => (string) $form->select_dolusers($search->filter('search_supervisor'), 'search_supervisor', 1, [], 0, '', '', '0', 0, 0, '', 0, '', 'maxwidth125'),
			'country' => (string) $form->select_country($search->filter('search_country'), 'search_country', '', 0, 'minwidth100imp maxwidth100'),
			'status' => $form->selectarray('search_status', ['-1' => '', '0' => $langs->trans('Disabled'), '1' => $langs->trans('Enabled')], $search->filter('search_status'), 0, 0, 0, '', 0, 0, 0, '', 'search_status width100 onrightofpage'),
		];
	}

	/**
	 * l.865: the extrafields cells of the filters row (extrafields_list_search_input.tpl.php).
	 *
	 * @param array<string, array<string, mixed>> $arrayfields
	 * @param array<string, mixed>                $searchOptions
	 */
	public function extraFieldsSearchInputs(array $arrayfields, array $searchOptions): string
	{
		$conf = $this->ctx->conf;
		$extrafields = $this->extraFields();
		$object = $this->legacyObject();
		$search_array_options = $searchOptions;
		ob_start();
		try {
			include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_input.tpl.php';
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * l.905-1000: the column titles before the printFieldListTitle hook, each counted in $totalarray['nbfield'].
	 *
	 * @param array<string, array<string, mixed>> $arrayfields
	 * @param array<string, mixed>                $totalarray
	 * @return list<string>
	 */
	public function titlesBeforeHook(array $arrayfields, string $param, UserSearch $search, string $selectedFields, array &$totalarray): array
	{
		$this->ctx->form();
		$file = $this->urls->generate('user_list');
		$titles = [];
		if ($this->checkboxLeft()) {
			$titles[] = getTitleFieldOfList($selectedFields, 0, $file, '', '', '', '', $search->sortField, $search->sortOrder, 'center maxwidthsearch ')."\n";
		}
		$privacy = getDolGlobalInt('MAIN_ENABLE_LOGINS_PRIVACY') != 0;
		$columns = [
			'u.rowid' => [(string) ($arrayfields['u.rowid']['label'] ?? ''), ''],
			'u.login' => [(string) ($arrayfields['u.login']['label'] ?? ''), ''],
			'u.lastname' => ['Lastname', ''],
			'u.firstname' => ['FirstName', ''],
			'u.gender' => ['Gender', 'center '],
			'u.employee' => ['Employee', 'center '],
			'u.fk_user' => ['HierarchicalResponsible', ''],
			'u.accountancy_code' => ['AccountancyCode', ''],
			'u.office_phone' => ['PhonePro', ''],
			'u.user_mobile' => ['PhoneMobile', ''],
			'u.email' => ['EMail', ''],
			'co.label' => ['Country', ''],
			'u.api_key' => ['ApiKey', ''],
			'u.fk_soc' => ['Company', ''],
			'u.entity' => [(string) ($arrayfields['u.entity']['label'] ?? ''), ''],
			'u.ref_employee' => ['RefEmployee', ''],
			'u.national_registration_number' => ['NationalRegistrationNumber', ''],
			'u.job' => [(string) ($arrayfields['u.job']['label'] ?? ''), ''],
			'u.salary' => ['Salary', 'right '],
			'u.thm' => ['THM', 'right '],
			'u.datelastlogin' => ['LastConnexion', 'center '],
			'u.datepreviouslogin' => ['PreviousConnexion', 'center '],
		];
		foreach ($columns as $key => [$label, $prefix]) {
			if (empty($arrayfields[$key]['checked']) || ($privacy && in_array($key, ['u.datelastlogin', 'u.datepreviouslogin'], true))) {
				continue;
			}
			$titles[] = getTitleFieldOfList($label, 0, $file, $key, '', $param, '', $search->sortField, $search->sortOrder, $prefix);
		}
		$totalarray['nbfield'] = (int) ($totalarray['nbfield'] ?? 0) + count($titles);

		// l.1000: extrafields_list_search_title.tpl.php
		$conf = $this->ctx->conf;
		$langs = $this->ctx->langs;
		$extrafields = $this->extraFields();
		$object = $this->legacyObject();
		$sortfield = $search->sortField;
		$sortorder = $search->sortOrder;
		ob_start();
		try {
			include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_title.tpl.php';
		} finally {
			$titles[] = (string) ob_get_clean();
		}

		return $titles;
	}

	/**
	 * l.1005-1029: the column titles after the printFieldListTitle hook.
	 *
	 * @param array<string, array<string, mixed>> $arrayfields
	 * @param array<string, mixed>                $totalarray
	 * @return list<string>
	 */
	public function titlesAfterHook(array $arrayfields, string $param, UserSearch $search, string $selectedFields, array &$totalarray): array
	{
		$file = $this->urls->generate('user_list');
		$titles = [];
		foreach (['u.datec' => ['DateCreationShort', 'center nowrap '], 'date_modification' => ['DateModificationShort', 'center nowrap '], 'u.import_key' => ['ImportId', 'center '], 'u.statut' => ['Status', 'center ']] as $key => [$label, $prefix]) {
			if (!empty($arrayfields[$key]['checked'])) {
				$titles[] = getTitleFieldOfList($label, 0, $file, $key, '', $param, '', $search->sortField, $search->sortOrder, $prefix);
			}
		}
		if (!$this->checkboxLeft()) {
			$titles[] = getTitleFieldOfList(($search->mode !== 'kanban' ? $selectedFields : ''), 0, $file, '', '', '', '', $search->sortField, $search->sortOrder, 'center maxwidthsearch ')."\n";
		}
		$totalarray['nbfield'] = (int) ($totalarray['nbfield'] ?? 0) + count($titles);

		return $titles;
	}

	/**
	 * l.1058-1080: a \User filled from the row, as list.php fills its $object (no query).
	 */
	public function legacyRow(UserListRow $listRow): \User
	{
		$db = $this->ctx->db;
		$obj = (object) $listRow->row;
		$object = new \User($db);
		$object->setVarsFromFetchObj($obj);
		$object->id = (int) $listRow->user->id;
		$object->admin = (int) $listRow->user->admin;
		$object->ref = (string) $listRow->user->id;
		$object->login = $listRow->user->login;
		$object->status = $listRow->user->status;
		$object->office_phone = (string) $listRow->user->officePhone;
		$object->user_mobile = (string) $listRow->user->userMobile;
		$object->job = (string) $listRow->user->job;
		$object->email = (string) $listRow->user->email;
		$object->gender = (string) $listRow->user->gender;
		$object->socid = (int) $listRow->user->companyId;
		$object->firstname = (string) $listRow->user->firstname;
		$object->lastname = (string) $listRow->user->lastname;
		$object->employee = (int) $listRow->user->employee;
		$object->photo = (string) $listRow->user->photo;
		$object->datestartvalidity = $db->jdate($listRow->row['datestartvalidity'] ?? '');
		$object->dateendvalidity = $db->jdate($listRow->row['dateendvalidity'] ?? '');
		$object->country_code = (string) $listRow->countryCode;
		$object->country = (string) $listRow->countryLabel;

		return $object;
	}

	/**
	 * l.1080-1401: the legacy cells of a row before the printFieldListValue hook, by column, and the cells counted
	 * in $totalarray (l.1047-1050: on the first row only; the salary total and the extrafields totals).
	 *
	 * @param array<string, array<string, mixed>> $arrayfields
	 * @param array<string, mixed>                $totalarray
	 * @return array<string, string|null>
	 */
	public function rowBeforeHook(UserListRow $listRow, \User $object, int $i, array $arrayfields, array &$totalarray, bool $canReadHrmData, bool $canReadSecretApi): array
	{
		$langs = $this->ctx->langs;
		$db = $this->ctx->db;
		$row = $listRow->row;
		$rowid = $listRow->user->id;
		$admin = $listRow->user->admin;
		$multicompany = isModEnabled('multicompany');
		$privacy = getDolGlobalInt('MAIN_ENABLE_LOGINS_PRIVACY') != 0;
		$checked = static fn (string $key): bool => !empty($arrayfields[$key]['checked']);
		$cells = [];

		// l.1080, l.1139-1147
		$login = $this->showUrls($object->getNomUrl(-1, '', 0, 0, 24, 1, 'login', '', 1));
		if ($multicompany && $admin && empty($listRow->user->entity)) {
			$login .= img_picto($langs->trans('SuperAdministratorDesc'), 'superadmin', 'class="valignmiddle paddingright paddingleft"');
		} elseif ($admin) {
			$login .= img_picto($langs->trans('AdministratorDesc'), 'admin', 'class="valignmiddle paddingright paddingleft"');
		}
		$cells['login'] = $login;
		$cells['employee'] = yn((int) $listRow->user->employee);

		// l.1200-1222
		$supervisor = '';
		if (!empty($row['login2'])) {
			$user2 = new \User($db);
			$user2->id = (int) ($row['id2'] ?? 0);
			$user2->login = (string) $row['login2'];
			$user2->lastname = (string) ($row['lastname2'] ?? '');
			$user2->firstname = (string) ($row['firstname2'] ?? '');
			$user2->gender = (string) ($row['gender2'] ?? '');
			$user2->photo = (string) ($row['photo2'] ?? '');
			$user2->admin = (int) ($row['admin2'] ?? 0);
			// l.1210-1211: the phones of the row's user, as list.php does
			$user2->office_phone = (string) $listRow->user->officePhone;
			$user2->user_mobile = (string) $listRow->user->userMobile;
			$user2->email = (string) ($row['email2'] ?? '');
			$user2->socid = (int) ($row['fk_soc2'] ?? 0);
			$user2->status = (int) ($row['status2'] ?? 0);
			if ($multicompany && !empty($row['admin2']) && empty($row['entity2'])) {
				$supervisor .= img_picto($langs->trans('SuperAdministratorDesc'), 'superadmin', 'class="valignmiddle paddingright"');
			} elseif (!empty($row['admin2'])) {
				$supervisor .= img_picto($langs->trans('AdministratorDesc'), 'admin', 'class="valignmiddle paddingright"');
			}
			$supervisor .= $this->showUrls($user2->getNomUrl(-1, '', 0, 0, 24, 0, '', '', 1));
		}
		$cells['supervisor'] = $supervisor;

		$cells['phone'] = dol_print_phone((string) $listRow->user->officePhone, (string) $listRow->countryCode, 0, $rowid, 'AC_TEL', ' ', 'phone');
		$cells['mobile'] = dol_print_phone((string) $listRow->user->userMobile, (string) $listRow->countryCode, 0, $rowid, 'AC_TEL', ' ', 'mobile');
		$showinvalidemail = (int) !getDolGlobalInt('MAIN_SHOW_INVALID_EMAIL_IN_LIST');
		$cells['email'] = dol_print_email((string) $listRow->user->email, $rowid, (int) $listRow->user->companyId, 1, 0, $showinvalidemail, 1);

		// l.1266-1278
		$apiKey = '';
		$apiKeyTitle = '';
		if ($checked('u.api_key')) {
			$clear = dolDecrypt((string) ($row['api_key'] ?? ''));
			// list.php l.1268 puts the clear key in the title for every viewer: only for those who may read it here
			$apiKeyTitle = $canReadSecretApi ? (string) $clear : '';
			if ($clear) {
				$apiKey = $canReadSecretApi
					? '<span class="opacitymedium">'.showValueWithClipboardCPButton($object->api_key, 1, dol_trunc($clear, 3)).'</span>'
					: '<span class="opacitymedium">'.$langs->trans('Hidden').'</span>';
			}
		}
		$cells['apiKey'] = $apiKey;
		$cells['apiKeyTitle'] = $apiKeyTitle;

		// l.1284-1296
		if ($listRow->user->companyId !== null && $listRow->user->companyId > 0) {
			$company = new \Societe($db);
			$company->id = $listRow->user->companyId;
			$company->name = (string) $listRow->companyName;
			$company->canvas = (string) ($row['canvas'] ?? '');
			$cells['company'] = $company->getNomUrl(1);
		} elseif ($listRow->user->isLdapManaged) {
			$cells['company'] = '<span class="opacitymedium">'.$langs->trans('DomainUser').'</span>';
		} else {
			$cells['company'] = '<span class="opacitymedium">'.$langs->trans('InternalUser').'</span>';
		}

		// l.1302-1317: the entity cell only with the multicompany object
		$mc = $this->ctx->multicompany();
		$cells['entity'] = null;
		if ($multicompany && $mc !== null && !getDolGlobalString('MULTICOMPANY_TRANSVERSE_MODE')) {
			if (empty($listRow->user->entity)) {
				$cells['entity'] = $langs->trans('AllEntities');
			} elseif (method_exists($mc, 'getInfo')) {
				$mc->getInfo($listRow->user->entity);
				$cells['entity'] = (string) ($mc->label ?? '');
			}
		}

		// l.1348-1383
		$salary = '';
		if ($listRow->user->salary) {
			$salary = $canReadHrmData ? price($listRow->user->salary) : '<span class="opacitymedium">'.$langs->trans('Hidden').'</span>';
		}
		$cells['salary'] = $salary;
		$cells['thm'] = $listRow->user->thm !== null ? price($listRow->user->thm) : '';
		$cells['lastLogin'] = dol_print_date($db->jdate($row['datelastlogin'] ?? ''), 'dayhour');
		$cells['previousLogin'] = dol_print_date($db->jdate($row['datepreviouslogin'] ?? ''), 'dayhour');

		// The cells counted as list.php counts them while printing (l.1117-1398)
		$before = ['u.rowid', 'u.login', 'u.lastname', 'u.firstname', 'u.gender', 'u.employee', 'u.fk_user', 'u.accountancy_code', 'u.office_phone', 'u.user_mobile', 'u.email', 'co.label', 'u.api_key', 'u.fk_soc'];
		$after = ['u.ref_employee', 'u.national_registration_number', 'u.job'];
		if (!$i) {
			$count = $this->checkboxLeft() ? 1 : 0;
			foreach ($before as $key) {
				$count += (int) $checked($key);
			}
			$count += (int) ($cells['entity'] !== null && $checked('u.entity'));
			foreach ($after as $key) {
				$count += (int) $checked($key);
			}
			$totalarray['nbfield'] = (int) ($totalarray['nbfield'] ?? 0) + $count;
		}
		if ($checked('u.salary')) {
			if (!$i) {
				$totalarray['nbfield']++;
				$totalarray['pos'][$totalarray['nbfield']] = 'u.salary';
			}
			$totalarray['val']['u.salary'] = (float) ($totalarray['val']['u.salary'] ?? 0) + (float) $listRow->user->salary;
		}
		if (!$i) {
			$totalarray['nbfield'] += (int) $checked('u.thm');
			$totalarray['nbfield'] += (int) (!$privacy && $checked('u.datelastlogin')) + (int) (!$privacy && $checked('u.datepreviouslogin'));
		}

		// l.1401: extrafields_list_print_fields.tpl.php
		$obj = (object) $row;
		$conf = $this->ctx->conf;
		$extrafields = $this->extraFields();
		ob_start();
		try {
			include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_print_fields.tpl.php';
		} finally {
			$cells['extraFields'] = (string) ob_get_clean();
		}

		return $cells;
	}

	/**
	 * l.1406-1452: the legacy cells after the printFieldListValue hook, and their count.
	 *
	 * @param array<string, array<string, mixed>> $arrayfields
	 * @param array<string, mixed>                $totalarray
	 * @return array<string, string>
	 */
	public function rowAfterHook(UserListRow $listRow, \User $object, int $i, array $arrayfields, array &$totalarray): array
	{
		$db = $this->ctx->db;
		if (!$i) {
			$count = 0;
			foreach (['u.datec', 'date_modification', 'u.import_key', 'u.statut'] as $key) {
				$count += (int) !empty($arrayfields[$key]['checked']);
			}
			$totalarray['nbfield'] = (int) ($totalarray['nbfield'] ?? 0) + $count + (int) !$this->checkboxLeft();
		}

		return [
			'dateCreation' => dol_print_date($db->jdate($listRow->row['date_creation'] ?? ''), 'dayhour', 'tzuser'),
			'dateModification' => dol_print_date($db->jdate($listRow->row['date_modification'] ?? ''), 'dayhour', 'tzuser'),
			'status' => $object->getLibStatut(5),
		];
	}

	/**
	 * l.1107: the kanban box of a user ($selected -1 without selection).
	 */
	public function kanban(\User $object, int $selected): string
	{
		// getNomUrl(0, ...) adds save_lastsearch_values on a PHP_SELF matching list.php only: added here, as on list.php
		return $this->showUrls($object->getKanbanView('', ['selected' => $selected]), true);
	}

	/**
	 * l.1461: the total line (list_print_total.tpl.php), '' when no column is totalized. With
	 * MAIN_GRANDTOTAL_LIST_SHOW, the template sums the columns over $sql, its select $sqlfields replaced.
	 *
	 * @param array<string, mixed> $totalarray
	 * @param string               $sql       The list query (UserPage::$sql)
	 * @param string               $sqlfields Its SELECT part (UserPage::$sqlFields)
	 */
	public function totals(array $totalarray, int $num, int $limit, int $offset, string $sql, string $sqlfields): string
	{
		$conf = $this->ctx->conf;
		$langs = $this->ctx->langs;
		$db = $this->ctx->db;
		$form = $this->ctx->form();
		ob_start();
		try {
			include DOL_DOCUMENT_ROOT.'/core/tpl/list_print_total.tpl.php';
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * The links of \User::getNomUrl() and of the kanban view to user/card.php?id=N point to the user_show route
	 * (the save_lastsearch_values flag kept).
	 */
	private function showUrls(string $html, bool $saveLastSearch = false): string
	{
		return (string) preg_replace_callback(
			'#href="'.preg_quote(DOL_URL_ROOT.'/user/card.php?id=', '#').'(\d+)(?:(&amp;|&)([^"]*))?"#',
			function (array $matches) use ($saveLastSearch): string {
				$query = isset($matches[3]) && $matches[3] !== '' ? $matches[3] : ($saveLastSearch ? 'save_lastsearch_values=1' : '');

				return 'href="'.$this->urls->generate('user_show', ['id' => (int) $matches[1]]).($query !== '' ? '?'.$query : '').'"';
			},
			$html
		);
	}
}
