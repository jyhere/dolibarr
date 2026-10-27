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

	public function __construct(private readonly Connection $connection, private readonly int $entity)
	{
	}

	public function find(int $id): ?User
	{
		return $this->fetch('u.rowid = '.$id);
	}

	public function findByLogin(string $login): ?User
	{
		return $this->fetch("u.login = '".$this->connection->escape($login)."'", ' ORDER BY u.entity DESC');
	}

	/**
	 * Number of users of the entity, superadmins (entity 0) included: getEntity('user') without multicompany.
	 */
	public function countInEntity(): int
	{
		$row = $this->connection->legacyFetchOneObject('SELECT COUNT(u.rowid) as nb FROM '.$this->connection->prefix().'user as u WHERE u.entity IN (0, '.$this->entity.')');

		return $row === null ? 0 : self::int(get_object_vars($row)['nb'] ?? 0);
	}

	/**
	 * The count card.php l.300-301 checks against main_limit_users: \User::getNbOfUsers('active'), the
	 * active users of getEntity('user', 0), which is "0,<entity>" without multicompany. With multicompany,
	 * the legacy count is the one of the multicompany module (getEntity() delegates to it): not reproduced.
	 */
	public function countForQuota(): int
	{
		$row = $this->connection->legacyFetchOneObject('SELECT COUNT(rowid) as nb FROM '.$this->connection->prefix().'user WHERE entity IN (0, '.$this->entity.') AND statut = 1');

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
	public function search(UserSearch $search, ?array $childIds, ?int $externalCompanyId, array $fragments = [], bool $withApiKey = false): UserPage
	{
		$prefix = $this->connection->prefix();
		$filters = $search->filters;
		$str = static fn (string $key): string => $search->filter($key);

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

		$sql = ' FROM '.$prefix.'user as u'
			.' LEFT JOIN '.$prefix.'user_extrafields as ef on (u.rowid = ef.fk_object)'
			.' LEFT JOIN '.$prefix.'societe as s ON u.fk_soc = s.rowid'
			.' LEFT JOIN '.$prefix.'user as u2 ON u.fk_user = u2.rowid'
			.' LEFT JOIN '.$prefix.'c_country as co ON u.fk_country = co.rowid'
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
			$sql .= " AND u.gender = '".$this->connection->escape($gender)."'";
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
			$sql .= dol_natural_search_phone($this->connection->legacy(), 'u.office_phone', $str('search_phonepro'));
		}
		if ($str('search_phonemobile') !== '') {
			$sql .= dol_natural_search_phone($this->connection->legacy(), 'u.user_mobile', $str('search_phonemobile'));
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
			$sql .= natural_search(array_keys($search->searchAllFields), $str('search_all'));
		}
		$sql .= $this->categorySql((int) $str('search_categ'), self::int($filters['search_category_user_operator'] ?? 0));
		if (UserSearch::positive($str('search_warehouse'))) {
			$sql .= ' AND u.fk_warehouse = '.(int) $str('search_warehouse');
		}
		if ($childIds !== null) {
			$sql .= ' AND u.rowid IN ('.($childIds === [] ? '0' : implode(',', array_map('intval', $childIds))).')';
		}
		$sql .= $fragments['where'] ?? '';

		$total = -1;
		if ($search->countTotal) {
			// l.538-539: the same query counted (DISTINCT dropped, as list.php's rewrite of the select)
			$row = $this->connection->legacyFetchOneObject('SELECT COUNT(*) as nbtotalofrecords'.(string) preg_replace('/GROUP BY .*$/', '', $sql));
			$total = $row === null ? 0 : self::int(get_object_vars($row)['nbtotalofrecords'] ?? 0);
			if ($search->offset() > $total) {
				$search = $search->firstPage();
			}
		}

		$db = $this->connection->legacy();
		$listSql = $select.$sql.$db->order($search->sortField, $search->sortOrder);
		$rows = $this->connection->legacyFetchAllObjects($listSql.$db->plimit($search->limit + 1, $search->offset()));
		$hasMore = count($rows) > $search->limit;
		$items = [];
		foreach (array_slice($rows, 0, $search->limit) as $row) {
			$items[] = $this->listRow($row);
		}

		return new UserPage($items, $total, $search->page, $search->limit, $hasMore, $listSql, $select);
	}

	private function fetch(string $where, string $order = ''): ?User
	{
		$sql = 'SELECT '.implode(', ', array_map(static fn (string $col): string => 'u.'.$col, self::COLUMNS)).', '.self::HAS_API_KEY_SQL.', '.self::IS_LDAP_MANAGED_SQL
			.' FROM '.$this->connection->prefix().'user as u'
			.' WHERE '.$where.' AND u.entity IN (0, '.$this->entity.')'
			.$order.' LIMIT 1';
		$row = $this->connection->legacyFetchOneObject($sql);

		return $row === null ? null : $this->hydrate($row);
	}

	/**
	 * list.php l.492-520 (with l.493: only search_categ is used): -2 is "without tag", an id a tag; the operator
	 * 1 joins the criteria with OR.
	 */
	private function categorySql(int $categ, int $operator): string
	{
		$prefix = $this->connection->prefix();
		if ($categ === -2) {
			$criteria = 'NOT EXISTS (SELECT ck.fk_user FROM '.$prefix.'categorie_user as ck WHERE u.rowid = ck.fk_user)';
		} elseif ($categ > 0) {
			$criteria = $operator === 0
				? ' EXISTS (SELECT ck.fk_user FROM '.$prefix.'categorie_user as ck WHERE u.rowid = ck.fk_user AND ck.fk_categorie = '.$categ.')'
				: ' EXISTS (SELECT ck.fk_user FROM '.$prefix.'categorie_user as ck WHERE u.rowid = ck.fk_user AND ck.fk_categorie IN ('.$categ.'))';
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
		/** @var array<string, mixed> $values */
		$values = get_object_vars($row);

		return new UserListRow(
			$this->hydrate($row),
			self::str($values['login2'] ?? null),
			self::str($values['firstname2'] ?? null),
			self::str($values['lastname2'] ?? null),
			self::str($values['name'] ?? null),
			self::str($values['country_code'] ?? null),
			self::str($values['country_label'] ?? null),
			self::date($values['date_modification'] ?? null),
			$values
		);
	}

	private function hydrate(object $row): User
	{
		/** @var array<string, mixed> $values */
		$values = get_object_vars($row);

		return new User(
			id: self::int($values['rowid'] ?? 0),
			entity: self::int($values['entity'] ?? 0),
			login: self::str($values['login'] ?? '') ?? '',
			admin: self::int($values['admin'] ?? 0) > 0,
			employee: self::int($values['employee'] ?? 0) > 0,
			status: self::int($values['statut'] ?? 0),
			lastname: self::str($values['lastname'] ?? null),
			firstname: self::str($values['firstname'] ?? null),
			gender: self::str($values['gender'] ?? null),
			civility: self::str($values['civility'] ?? null),
			job: self::str($values['job'] ?? null),
			email: self::str($values['email'] ?? null),
			officePhone: self::str($values['office_phone'] ?? null),
			userMobile: self::str($values['user_mobile'] ?? null),
			photo: self::str($values['photo'] ?? null),
			lang: self::str($values['lang'] ?? null),
			color: self::str($values['color'] ?? null),
			dateStartValidity: self::date($values['datestartvalidity'] ?? null),
			dateEndValidity: self::date($values['dateendvalidity'] ?? null),
			hasApiKey: self::int($values['has_api_key'] ?? 0) === 1,
			personalEmail: self::str($values['personal_email'] ?? null),
			personalMobile: self::str($values['personal_mobile'] ?? null),
			address: self::str($values['address'] ?? null),
			zip: self::str($values['zip'] ?? null),
			town: self::str($values['town'] ?? null),
			stateId: self::nint($values['fk_state'] ?? null),
			countryId: self::nint($values['fk_country'] ?? null),
			birth: self::date($values['birth'] ?? null),
			birthPlace: self::str($values['birth_place'] ?? null),
			signature: self::str($values['signature'] ?? null),
			dateLastLogin: self::date($values['datelastlogin'] ?? null),
			datePreviousLogin: self::date($values['datepreviouslogin'] ?? null),
			notePublic: self::str($values['note_public'] ?? null),
			notePrivate: self::str($values['note_private'] ?? null),
			companyId: self::nint($values['fk_soc'] ?? null),
			contactId: self::nint($values['fk_socpeople'] ?? null),
			memberId: self::nint($values['fk_member'] ?? null),
			supervisorId: self::nint($values['fk_user'] ?? null),
			expenseValidatorId: self::nint($values['fk_user_expense_validator'] ?? null),
			holidayValidatorId: self::nint($values['fk_user_holiday_validator'] ?? null),
			refEmployee: self::str($values['ref_employee'] ?? null),
			refExt: self::str($values['ref_ext'] ?? null),
			dateCreated: self::date($values['datec'] ?? null),
			dateModified: self::date($values['tms'] ?? null),
			salary: self::float($values['salary'] ?? null),
			salaryExtra: self::float($values['salaryextra'] ?? null),
			thm: self::float($values['thm'] ?? null),
			tjm: self::float($values['tjm'] ?? null),
			weeklyHours: self::float($values['weeklyhours'] ?? null),
			dateEmployment: self::date($values['dateemployment'] ?? null),
			dateEmploymentEnd: self::date($values['dateemploymentend'] ?? null),
			officeFax: self::str($values['office_fax'] ?? null),
			accountancyCode: self::str($values['accountancy_code'] ?? null),
			warehouseId: self::nint($values['fk_warehouse'] ?? null),
			nationalRegistrationNumber: self::str($values['national_registration_number'] ?? null),
			isLdapManaged: self::int($values['is_ldap_managed'] ?? 0) === 1,
			forcePasswordChange: self::int($values['force_pass_change'] ?? 0) > 0
		);
	}

	private static function int(mixed $value): int
	{
		return is_numeric($value) ? (int) $value : 0;
	}

	private static function nint(mixed $value): ?int
	{
		return is_numeric($value) ? (int) $value : null;
	}

	private static function float(mixed $value): ?float
	{
		return is_numeric($value) ? (float) $value : null;
	}

	private static function str(mixed $value): ?string
	{
		return is_scalar($value) ? (string) $value : null;
	}

	private static function date(mixed $value): ?\DateTimeImmutable
	{
		if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
			return null;
		}
		try {
			return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
		} catch (\Exception) {
			return null;
		}
	}
}
