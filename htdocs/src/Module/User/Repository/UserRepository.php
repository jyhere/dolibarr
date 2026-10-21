<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Repository;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityRepository;
use Dolibarr\Module\User\Entity\User;

/**
 * Read-only access to llx_user. Secret columns are never selected.
 */
final class UserRepository implements EntityRepository
{
	/** Computed in SQL so the API key value itself is never loaded. */
	public const HAS_API_KEY_SQL = "CASE WHEN u.api_key IS NOT NULL AND u.api_key <> '' THEN 1 ELSE 0 END AS has_api_key";

	/** Computed in SQL so the LDAP SID itself is never loaded. */
	public const IS_LDAP_MANAGED_SQL = "CASE WHEN u.ldap_sid IS NOT NULL AND u.ldap_sid <> '' THEN 1 ELSE 0 END AS is_ldap_managed";

	public const COLUMNS = [
		'rowid', 'entity', 'login', 'admin', 'employee', 'statut',
		'lastname', 'firstname', 'gender', 'civility', 'job', 'email',
		'office_phone', 'user_mobile', 'photo', 'lang', 'color',
		'datestartvalidity', 'dateendvalidity',
		'personal_email', 'personal_mobile', 'address', 'zip', 'town',
		'fk_state', 'fk_country', 'birth', 'birth_place', 'signature',
		'datelastlogin', 'datepreviouslogin', 'note_public', 'note_private',
		'fk_soc', 'fk_socpeople', 'fk_member', 'fk_user',
		'fk_user_expense_validator', 'fk_user_holiday_validator',
		'ref_employee', 'ref_ext', 'datec', 'tms',
		'salary', 'salaryextra', 'thm', 'tjm', 'weeklyhours',
		'dateemployment', 'dateemploymentend',
		'office_fax', 'accountancy_code', 'fk_warehouse', 'national_registration_number',
		'force_pass_change',
	];

	public function __construct(private readonly Connection $c, private readonly int $entity)
	{
	}

	public function find(int $id): ?User
	{
		return $this->fetch('u.rowid = '.$id);
	}

	public function findByLogin(string $login): ?User
	{
		return $this->fetch("u.login = '".$this->c->escape($login)."'", ' ORDER BY u.entity DESC');
	}

	/**
	 * Number of users of the entity, superadmins (entity 0) included: getEntity('user') without multicompany.
	 */
	public function countInEntity(): int
	{
		$row = $this->c->fetchOne('SELECT COUNT(u.rowid) as nb FROM '.$this->c->prefix().'user as u WHERE u.entity IN (0, '.$this->entity.')');

		return $row === null ? 0 : self::int(get_object_vars($row)['nb'] ?? 0);
	}

	/**
	 * The count card.php l.300-301 checks against main_limit_users: \User::getNbOfUsers('active'), the
	 * active users of getEntity('user', 0), which is "0,<entity>" without multicompany. With multicompany,
	 * the legacy count is the one of the multicompany module (getEntity() delegates to it): not reproduced.
	 */
	public function countForQuota(): int
	{
		$row = $this->c->fetchOne('SELECT COUNT(rowid) as nb FROM '.$this->c->prefix().'user WHERE entity IN (0, '.$this->entity.') AND statut = 1');

		return $row === null ? 0 : self::int(get_object_vars($row)['nb'] ?? 0);
	}

	/**
	 * The user list, the query of htdocs/user/list.php l.398-559: the same SELECT (plus the columns the User entity
	 * needs), joins, criteria, count and order, $limit + 1 rows read to know whether a next page exists.
	 *
	 * The SQL fragments of the criteria come from the legacy builders natural_search() and dol_natural_search_phone()
	 * (they escape the value) and the entities from getEntity('user'), as in list.php. The IN () lists (status,
	 * supervisor, country, tag) are rebuilt from the integers of the criterion, where list.php passes the sanitized
	 * string: a hostile value ('1,,2', '1) OR (1') cannot make the query fail (Review Focus 4).
	 *
	 * @param ?list<int>                                            $childIds          Only these users (the employee list
	 *                                                                                 without salaries->readall, l.524-526), null for all
	 * @param ?int                                                  $externalCompanyId The company of an external current user (l.434-436)
	 * @param array{select?: string, from?: string, where?: string} $fragments         Legacy SQL added at the places list.php
	 *                                                                                 adds it: the extrafields columns and the resPrint of
	 *                                                                                 printFieldListSelect (l.408-417), printFieldListFrom (l.428-432),
	 *                                                                                 the extrafields criteria and printFieldListWhere (l.528-532)
	 * @param bool                                                  $withApiKey        Select the (encrypted) api_key, for the ApiKey column
	 * @throws \Dolibarr\Core\Database\DatabaseException
	 */
	public function search(UserSearch $s, ?array $childIds, ?int $externalCompanyId, array $fragments = [], bool $withApiKey = false): UserPage
	{
		$p = $this->c->prefix();
		$f = $s->filters;
		$str = static fn (string $k): string => $s->filter($k);

		$select = 'SELECT DISTINCT '.implode(', ', array_map(static fn (string $col): string => 'u.'.$col, self::COLUMNS))
			.', '.self::HAS_API_KEY_SQL.', '.self::IS_LDAP_MANAGED_SQL
			.($withApiKey ? ', u.api_key' : '')
			.', u.statut as status, u.import_key'
			.', GREATEST(u.tms, COALESCE(ef.tms, u.tms)) as date_modification, u.datec as date_creation'
			.', u2.rowid as id2, u2.login as login2, u2.firstname as firstname2, u2.lastname as lastname2, u2.admin as admin2, u2.fk_soc as fk_soc2,'
			.' u2.office_phone as ofice_phone2, u2.user_mobile as user_mobile2, u2.email as email2, u2.gender as gender2, u2.photo as photo2, u2.entity as entity2, u2.statut as status2'
			.', s.nom as name, s.canvas'
			.', co.code as country_code, co.label as country_label'
			.($fragments['select'] ?? '');
		$select = (string) preg_replace('/,\s*$/', '', $select);

		$sql = ' FROM '.$p.'user as u'
			.' LEFT JOIN '.$p.'user_extrafields as ef on (u.rowid = ef.fk_object)'
			.' LEFT JOIN '.$p.'societe as s ON u.fk_soc = s.rowid'
			.' LEFT JOIN '.$p.'user as u2 ON u.fk_user = u2.rowid'
			.' LEFT JOIN '.$p.'c_country as co ON u.fk_country = co.rowid'
			.($fragments['from'] ?? '')
			.' WHERE u.entity IN ('.getEntity('user').')';
		if ($externalCompanyId !== null && $externalCompanyId > 0) {
			$sql .= ' AND u.fk_soc = '.$externalCompanyId;
		}
		if (UserSearch::positive($str('search_supervisor'))) {
			$sql .= self::inInts('u.fk_user', $str('search_supervisor'));
		}
		if ($str('search_thirdparty') !== '') {
			$sql .= natural_search(['s.nom'], $str('search_thirdparty'));
		}
		if (UserSearch::positive($str('search_warehouse'))) {
			$sql .= natural_search(['u.fk_warehouse'], $str('search_warehouse'));
		}
		if ($str('search_rowid') !== '') {
			$sql .= natural_search('u.rowid', $str('search_rowid'), 1);
		}
		foreach (['search_login' => 'u.login', 'search_lastname' => 'u.lastname', 'search_firstname' => 'u.firstname'] as $key => $column) {
			if ($str($key) !== '') {
				$sql .= natural_search($column, $str($key));
			}
		}
		$gender = $str('search_gender');
		if ($gender !== '' && $gender !== '-1') {
			// Not natural_search(): %man% would also find woman (l.460)
			$sql .= " AND u.gender = '".$this->c->escape($gender)."'";
		}
		$employee = $str('search_employee');
		if (is_numeric($employee) && $employee >= 0) {
			$sql .= ' AND u.employee = '.(int) $employee;
		}
		if ($str('search_accountancy_code') !== '') {
			$sql .= natural_search('u.accountancy_code', $str('search_accountancy_code'));
		}
		if ($str('search_phonepro') !== '' || $str('search_phonemobile') !== '') {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/phone.lib.php';
		}
		if ($str('search_phonepro') !== '') {
			$sql .= dol_natural_search_phone($this->c->legacy(), 'u.office_phone', $str('search_phonepro'));
		}
		if ($str('search_phonemobile') !== '') {
			$sql .= dol_natural_search_phone($this->c->legacy(), 'u.user_mobile', $str('search_phonemobile'));
		}
		if ($str('search_email') !== '') {
			$sql .= natural_search('u.email', $str('search_email'));
		}
		if ($str('search_country') !== '') {
			$sql .= self::inInts('u.fk_country', $str('search_country'));
		}
		if ($str('search_api_key') !== '') {
			$sql .= natural_search('u.api_key', $str('search_api_key'));
		}
		if ($str('search_job') !== '') {
			$sql .= natural_search(['u.job'], $str('search_job'));
		}
		$status = $str('search_status');
		if ($status !== '' && (!is_numeric($status) || (int) $status >= 0)) {
			$sql .= self::inInts('u.statut', $status);
		}
		if ($str('search_all') !== '') {
			$sql .= natural_search(array_keys($s->searchAllFields), $str('search_all'));
		}
		$sql .= $this->categorySql((int) $str('search_categ'), self::int($f['search_category_user_operator'] ?? 0));
		if (UserSearch::positive($str('search_warehouse'))) {
			$sql .= ' AND u.fk_warehouse = '.(int) $str('search_warehouse');
		}
		if ($childIds !== null) {
			$sql .= ' AND u.rowid IN ('.($childIds === [] ? '0' : implode(',', array_map('intval', $childIds))).')';
		}
		$sql .= $fragments['where'] ?? '';

		$total = -1;
		if ($s->countTotal) {
			// l.538-539: the same query counted (DISTINCT dropped, as list.php's rewrite of the select)
			$row = $this->c->fetchOne('SELECT COUNT(*) as nbtotalofrecords'.(string) preg_replace('/GROUP BY .*$/', '', $sql));
			$total = $row === null ? 0 : self::int(get_object_vars($row)['nbtotalofrecords'] ?? 0);
			if ($s->offset() > $total) {
				$s = $s->firstPage();
			}
		}

		$db = $this->c->legacy();
		$listSql = $select.$sql.$db->order($s->sortField, $s->sortOrder);
		$rows = $this->c->fetchAll($listSql.$db->plimit($s->limit + 1, $s->offset()));
		$hasMore = count($rows) > $s->limit;
		$items = [];
		foreach (array_slice($rows, 0, $s->limit) as $row) {
			$items[] = $this->listRow($row);
		}

		return new UserPage($items, $total, $s->page, $s->limit, $hasMore, $listSql, $select);
	}

	private function fetch(string $where, string $order = ''): ?User
	{
		$sql = 'SELECT '.implode(', ', array_map(static fn (string $col): string => 'u.'.$col, self::COLUMNS)).', '.self::HAS_API_KEY_SQL.', '.self::IS_LDAP_MANAGED_SQL
			.' FROM '.$this->c->prefix().'user as u'
			.' WHERE '.$where.' AND u.entity IN (0, '.$this->entity.')'
			.$order.' LIMIT 1';
		$row = $this->c->fetchOne($sql);

		return $row === null ? null : $this->hydrate($row);
	}

	/**
	 * list.php l.492-520 (with l.493: only search_categ is used): -2 is "without tag", an id a tag; the operator
	 * 1 joins the criteria with OR.
	 */
	private function categorySql(int $categ, int $operator): string
	{
		$p = $this->c->prefix();
		if ($categ === -2) {
			$criteria = 'NOT EXISTS (SELECT ck.fk_user FROM '.$p.'categorie_user as ck WHERE u.rowid = ck.fk_user)';
		} elseif ($categ > 0) {
			$criteria = $operator === 0
				? ' EXISTS (SELECT ck.fk_user FROM '.$p.'categorie_user as ck WHERE u.rowid = ck.fk_user AND ck.fk_categorie = '.$categ.')'
				: ' EXISTS (SELECT ck.fk_user FROM '.$p.'categorie_user as ck WHERE u.rowid = ck.fk_user AND ck.fk_categorie IN ('.$categ.'))';
		} else {
			return '';
		}

		return ' AND ('.$criteria.')';
	}

	/**
	 * " AND $column IN (...)" of the integers of a comma list, as list.php's IN ($db->sanitize(...)) but never invalid.
	 */
	private static function inInts(string $column, string $list): string
	{
		$ids = [];
		foreach (explode(',', $list) as $part) {
			$part = trim($part);
			if (preg_match('/^-?\d+$/', $part) === 1) {
				$ids[] = (int) $part;
			}
		}

		return ' AND '.$column.' IN ('.($ids === [] ? 'NULL' : implode(',', $ids)).')';
	}

	private function listRow(object $row): UserListRow
	{
		/** @var array<string, mixed> $r */
		$r = get_object_vars($row);

		return new UserListRow(
			$this->hydrate($row),
			self::str($r['login2'] ?? null),
			self::str($r['firstname2'] ?? null),
			self::str($r['lastname2'] ?? null),
			self::str($r['name'] ?? null),
			self::str($r['country_code'] ?? null),
			self::str($r['country_label'] ?? null),
			self::date($r['date_modification'] ?? null),
			$r
		);
	}

	private function hydrate(object $row): User
	{
		/** @var array<string, mixed> $r */
		$r = get_object_vars($row);

		return new User(
			id: self::int($r['rowid'] ?? 0),
			entity: self::int($r['entity'] ?? 0),
			login: self::str($r['login'] ?? '') ?? '',
			admin: self::int($r['admin'] ?? 0) > 0,
			employee: self::int($r['employee'] ?? 0) > 0,
			status: self::int($r['statut'] ?? 0),
			lastname: self::str($r['lastname'] ?? null),
			firstname: self::str($r['firstname'] ?? null),
			gender: self::str($r['gender'] ?? null),
			civility: self::str($r['civility'] ?? null),
			job: self::str($r['job'] ?? null),
			email: self::str($r['email'] ?? null),
			officePhone: self::str($r['office_phone'] ?? null),
			userMobile: self::str($r['user_mobile'] ?? null),
			photo: self::str($r['photo'] ?? null),
			lang: self::str($r['lang'] ?? null),
			color: self::str($r['color'] ?? null),
			dateStartValidity: self::date($r['datestartvalidity'] ?? null),
			dateEndValidity: self::date($r['dateendvalidity'] ?? null),
			hasApiKey: self::int($r['has_api_key'] ?? 0) === 1,
			personalEmail: self::str($r['personal_email'] ?? null),
			personalMobile: self::str($r['personal_mobile'] ?? null),
			address: self::str($r['address'] ?? null),
			zip: self::str($r['zip'] ?? null),
			town: self::str($r['town'] ?? null),
			stateId: self::nint($r['fk_state'] ?? null),
			countryId: self::nint($r['fk_country'] ?? null),
			birth: self::date($r['birth'] ?? null),
			birthPlace: self::str($r['birth_place'] ?? null),
			signature: self::str($r['signature'] ?? null),
			dateLastLogin: self::date($r['datelastlogin'] ?? null),
			datePreviousLogin: self::date($r['datepreviouslogin'] ?? null),
			notePublic: self::str($r['note_public'] ?? null),
			notePrivate: self::str($r['note_private'] ?? null),
			companyId: self::nint($r['fk_soc'] ?? null),
			contactId: self::nint($r['fk_socpeople'] ?? null),
			memberId: self::nint($r['fk_member'] ?? null),
			supervisorId: self::nint($r['fk_user'] ?? null),
			expenseValidatorId: self::nint($r['fk_user_expense_validator'] ?? null),
			holidayValidatorId: self::nint($r['fk_user_holiday_validator'] ?? null),
			refEmployee: self::str($r['ref_employee'] ?? null),
			refExt: self::str($r['ref_ext'] ?? null),
			dateCreated: self::date($r['datec'] ?? null),
			dateModified: self::date($r['tms'] ?? null),
			salary: self::float($r['salary'] ?? null),
			salaryExtra: self::float($r['salaryextra'] ?? null),
			thm: self::float($r['thm'] ?? null),
			tjm: self::float($r['tjm'] ?? null),
			weeklyHours: self::float($r['weeklyhours'] ?? null),
			dateEmployment: self::date($r['dateemployment'] ?? null),
			dateEmploymentEnd: self::date($r['dateemploymentend'] ?? null),
			officeFax: self::str($r['office_fax'] ?? null),
			accountancyCode: self::str($r['accountancy_code'] ?? null),
			warehouseId: self::nint($r['fk_warehouse'] ?? null),
			nationalRegistrationNumber: self::str($r['national_registration_number'] ?? null),
			isLdapManaged: self::int($r['is_ldap_managed'] ?? 0) === 1,
			forcePasswordChange: self::int($r['force_pass_change'] ?? 0) > 0
		);
	}

	private static function int(mixed $v): int
	{
		return is_numeric($v) ? (int) $v : 0;
	}

	private static function nint(mixed $v): ?int
	{
		return is_numeric($v) ? (int) $v : null;
	}

	private static function float(mixed $v): ?float
	{
		return is_numeric($v) ? (float) $v : null;
	}

	private static function str(mixed $v): ?string
	{
		return is_scalar($v) ? (string) $v : null;
	}

	private static function date(mixed $v): ?\DateTimeImmutable
	{
		if (!is_string($v) || $v === '' || str_starts_with($v, '0000-00-00')) {
			return null;
		}
		try {
			return new \DateTimeImmutable($v, new \DateTimeZone('UTC'));
		} catch (\Exception) {
			return null;
		}
	}
}
