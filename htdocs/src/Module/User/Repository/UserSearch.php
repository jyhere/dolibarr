<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Repository;

use Dolibarr\Core\Config;
use Dolibarr\Core\Http\Request;

/**
 * The search criteria, sort and pagination of htdocs/user/list.php (l.51-79, l.100, l.117-131 and l.179-224),
 * read with the same names and GETPOST() filters (GET then POST, as list.php). Unlike list.php, the sort,
 * the limit and the page are clamped so that no request can make the SQL fail (Review Focus 4): an unknown
 * sort field falls back to u.login, a sort order other than ASC or DESC to ASC, a limit outside [1, 1000] to
 * $conf->liste_limit and a negative page to 0.
 */
final class UserSearch
{
	/** The columns of $arrayfields (list.php l.137-170) the list may be sorted on. */
	public const SORTABLE = [
		'u.rowid', 'u.login', 'u.lastname', 'u.firstname', 'u.entity', 'u.gender', 'u.employee', 'u.fk_user',
		'u.accountancy_code', 'u.office_phone', 'u.user_mobile', 'u.email', 'co.label', 'u.api_key', 'u.fk_soc',
		'u.ref_employee', 'u.national_registration_number', 'u.job', 'u.salary', 'u.thm', 'u.datec',
		'date_modification', 'u.import_key', 'u.statut', 'u.datelastlogin', 'u.datepreviouslogin',
	];

	public const MAX_LIMIT = 1000;

	/**
	 * The search_* parameters of list.php l.179-224, by name, and their GETPOST() filter. search_category_user_list
	 * (array:int) and search_category_user_operator are read apart.
	 */
	private const FILTERS = [
		'search_all' => 'alphanohtml',
		'search_user' => 'alpha',
		'search_rowid' => 'alpha',
		'search_login' => 'alpha',
		'search_lastname' => 'alpha',
		'search_firstname' => 'alpha',
		'search_gender' => 'alpha',
		'search_employee' => 'alpha',
		'search_accountancy_code' => 'alpha',
		'search_phonepro' => 'alpha',
		'search_phonemobile' => 'alpha',
		'search_email' => 'alpha',
		'search_country' => 'alpha',
		'search_api_key' => 'alphanohtml',
		'search_status' => 'intcomma',
		'search_thirdparty' => 'alpha',
		'search_job' => 'alpha',
		'search_warehouse' => 'alpha',
		'search_supervisor' => 'intcomma',
		'search_categ' => 'intcomma',
	];

	/** The parameters list.php l.584-650 adds to $param, in that order. */
	private const PARAM_ORDER = [
		'search_all', 'search_user', 'search_rowid', 'search_login', 'search_lastname', 'search_firstname',
		'search_gender', 'search_employee', 'search_accountancy_code', 'search_phonepro', 'search_phonemobile',
		'search_email', 'search_country', 'search_api_key', 'search_supervisor', 'search_status', 'search_categ',
		'search_warehouse',
	];

	/**
	 * @param array<string, mixed>  $filters           Every search_* of list.php l.179-224 under its name (strings,
	 *                                                 the dates as int|string, search_category_user_list as list<int>,
	 *                                                 search_category_user_operator as int)
	 * @param array<string, string> $searchAllFields   $fieldstosearchall of list.php l.117-131 (column => label key)
	 * @param bool                  $countTotal        The total is counted (MAIN_DISABLE_FULL_SCANLIST unset, l.536)
	 * @param bool                  $loginsPrivacy     MAIN_ENABLE_LOGINS_PRIVACY (l.167, l.300)
	 */
	private function __construct(
		public readonly array $filters,
		public readonly string $sortField,
		public readonly string $sortOrder,
		public readonly int $limit,
		public readonly int $page,
		public readonly string $optioncss,
		public readonly string $mode,
		public readonly string $contextpage,
		public readonly bool $employeeList,
		public readonly array $searchAllFields,
		public readonly bool $countTotal,
		private readonly bool $loginsPrivacy
	) {
	}

	/**
	 * @param int          $listLimit     $conf->liste_limit (main.inc.php l.1249-1256); 0 reads MAIN_SIZE_LISTE_LIMIT
	 * @param list<string> $extraSortable The extrafields columns of the list (ef.<name>), sortable too
	 */
	public static function fromRequest(Request $r, Config $config, bool $employeeList, int $listLimit = 0, array $extraSortable = []): self
	{
		if ($listLimit <= 0) {
			$userLimit = $config->userString('MAIN_SIZE_LISTE_LIMIT');
			$listLimit = $userLimit !== '' ? (int) $userLimit : $config->int('MAIN_SIZE_LISTE_LIMIT', 15);
			$listLimit = $listLimit > 0 ? $listLimit : 15;
		}

		// l.69: GETPOSTINT('limit') or $conf->liste_limit, here within [1, MAX_LIMIT]
		$limit = $r->getInt('limit');
		if ($limit < 1 || $limit > self::MAX_LIMIT) {
			$limit = $listLimit;
		}
		// l.72-76
		$page = $r->isSet('pageplusone') ? $r->getInt('pageplusone') - 1 : $r->getInt('page');
		if ($page < 0 || self::str($r, 'button_search', 'alpha') !== '' || self::str($r, 'button_removefilter', 'alpha') !== '') {
			$page = 0;
		}

		$filters = [];
		foreach (self::FILTERS as $name => $filter) {
			$filters[$name] = self::str($r, $name, $filter);
		}
		$filters['search_all'] = trim($filters['search_all']);
		$filters['search_datelastlogin'] = self::date($r, 'search_datelastlogin');
		$filters['search_datepreviouslogin'] = self::date($r, 'search_datepreviouslogin');

		// l.202-216
		$operator = 0;
		if ($r->isSet('formfilteraction')) {
			$operator = $r->getInt('search_category_user_operator');
		} elseif ($config->string('MAIN_SEARCH_CAT_OR_BY_DEFAULT') !== '') {
			$operator = $config->int('MAIN_SEARCH_CAT_OR_BY_DEFAULT');
		}
		$filters['search_category_user_operator'] = $operator;
		$categoryList = [];
		$posted = $r->get('search_category_user_list', 'array:int', []);
		foreach (is_array($posted) ? $posted : [] as $id) {
			if (is_numeric($id)) {
				$categoryList[] = (int) $id;
			}
		}
		$catid = $r->getInt('catid');
		if ($catid !== 0 && $categoryList === []) {
			$categoryList = [$catid];
		}
		if ($catid !== 0 && ($filters['search_categ'] === '' || $filters['search_categ'] === '0')) {
			$filters['search_categ'] = (string) $catid;
		}
		$filters['search_category_user_list'] = $categoryList;

		// l.218-224
		if ($filters['search_status'] === '' && $filters['search_all'] === '') {
			$filters['search_status'] = '1';
		}
		if ($employeeList && !$r->isSet('search_employee')) {
			$filters['search_employee'] = '1';
		}

		// l.117-131
		$searchAll = [
			'u.login' => 'Login', 'u.lastname' => 'Lastname', 'u.firstname' => 'Firstname',
			'u.accountancy_code' => 'AccountancyCode', 'u.office_phone' => 'PhonePro', 'u.user_mobile' => 'PhoneMobile',
			'u.email' => 'EMail', 'co.label' => 'Country', 'u.note_public' => 'NotePublic', 'u.note_private' => 'NotePrivate',
		];
		if ($config->isModuleEnabled('api')) {
			$searchAll['u.api_key'] = 'ApiKey';
		}

		$contextpage = self::str($r, 'contextpage', 'aZ');
		$optioncss = self::str($r, 'optioncss', 'aZ');
		$mode = self::str($r, 'mode', 'aZ');

		return new self(
			$filters,
			self::sortField(self::str($r, 'sortfield', 'aZ09comma'), $extraSortable),
			strtoupper(self::str($r, 'sortorder', 'aZ09comma')) === 'DESC' ? 'DESC' : 'ASC',
			$limit,
			$page,
			$optioncss === 'print' ? 'print' : '',
			in_array($mode, ['common', 'kanban'], true) ? $mode : '',
			// l.57: 'userlist' (the directory and file names) when none is given
			$contextpage !== '' ? $contextpage : ($employeeList ? 'employeelist' : 'userlist'),
			$employeeList,
			$searchAll,
			$config->int('MAIN_DISABLE_FULL_SCANLIST') === 0,
			$config->int('MAIN_ENABLE_LOGINS_PRIVACY') !== 0
		);
	}

	/**
	 * The criteria emptied by the "remove filters" button (list.php l.277-304): no default status any more.
	 */
	public function reset(): self
	{
		$filters = $this->filters;
		foreach (self::FILTERS as $name => $filter) {
			$filters[$name] = '';
		}
		$filters['search_categ'] = '0';
		if (!$this->loginsPrivacy) {
			$filters['search_datelastlogin'] = '';
			$filters['search_datepreviouslogin'] = '';
		}

		return new self($filters, $this->sortField, $this->sortOrder, $this->limit, 0, $this->optioncss, $this->mode, $this->contextpage, $this->employeeList, $this->searchAllFields, $this->countTotal, $this->loginsPrivacy);
	}

	/**
	 * The same criteria on the first page (list.php l.548-551: a page beyond the total).
	 */
	public function firstPage(): self
	{
		return new self($this->filters, $this->sortField, $this->sortOrder, $this->limit, 0, $this->optioncss, $this->mode, $this->contextpage, $this->employeeList, $this->searchAllFields, $this->countTotal, $this->loginsPrivacy);
	}

	public function offset(): int
	{
		return $this->limit * $this->page;
	}

	/**
	 * A filter as a string ('' when absent or not a string).
	 */
	public function filter(string $name): string
	{
		$v = $this->filters[$name] ?? '';

		return is_scalar($v) ? (string) $v : '';
	}

	/**
	 * $param of list.php l.584-650 (the extrafields part excepted), the query string of the sort, page and
	 * view links: each '&key=value', urlencoded.
	 *
	 * @param int $listLimit $conf->liste_limit: a limit equal to it is not repeated (l.591)
	 */
	public function param(int $listLimit): string
	{
		$param = '';
		if ($this->mode !== '') {
			$param .= '&mode='.urlencode($this->mode);
		}
		// l.588: the contextpage always differs from PHP_SELF
		if ($this->contextpage !== '') {
			$param .= '&contextpage='.urlencode($this->contextpage);
		}
		if ($this->limit !== $listLimit) {
			$param .= '&limit='.$this->limit;
		}
		if ($this->optioncss !== '') {
			$param .= '&optioncss='.urlencode($this->optioncss);
		}
		foreach (self::PARAM_ORDER as $name) {
			$v = $this->filter($name);
			$add = match ($name) {
				'search_gender', 'search_employee' => $v !== '' && $v !== '-1',
				'search_supervisor', 'search_warehouse', 'search_categ' => self::positive($v),
				default => $v !== '',
			};
			if ($add) {
				$param .= '&'.$name.'='.urlencode($v);
			}
		}

		return $param;
	}

	/**
	 * PHP's loose "$v > 0" of list.php on a string criterion ('3,4' > 0 holds).
	 */
	public static function positive(string $v): bool
	{
		return is_numeric($v) ? (float) $v > 0 : $v > '0';
	}

	/**
	 * @param list<string> $extraSortable
	 */
	private static function sortField(string $field, array $extraSortable): string
	{
		if ($field === '') {
			return 'u.login';
		}
		foreach (explode(',', $field) as $part) {
			if (!in_array($part, self::SORTABLE, true) && !in_array($part, $extraSortable, true)) {
				return 'u.login';
			}
		}

		return $field;
	}

	private static function str(Request $r, string $name, string $filter): string
	{
		$v = $r->get($name, $filter, '');

		return is_scalar($v) ? (string) $v : '';
	}

	/**
	 * GETPOSTDATE($name, '', 'tzuserrel'): a timestamp, '' when not given.
	 */
	private static function date(Request $r, string $name): int|string
	{
		$v = $r->get($name, 'date', '');

		return is_int($v) ? $v : '';
	}
}
