<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Repository;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityRepository;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyEntityScope;
use Dolibarr\Core\Legacy\LegacyTranslator;
use Dolibarr\Core\Orm\Cast\DateCast;
use Dolibarr\Core\Orm\Cast\IntCast;
use Dolibarr\Core\Orm\Cast\StringCast;
use Dolibarr\Core\Orm\Hydrator;
use Dolibarr\Core\Orm\MappingRegistry;
use Dolibarr\Core\Orm\NaturalSearch;
use Dolibarr\Core\Orm\Orm;
use Dolibarr\Core\Orm\Query;
use Dolibarr\Core\Orm\Repository;
use Dolibarr\Core\Orm\Snapshots;
use Dolibarr\Core\Translator;
use Dolibarr\Module\User\Entity\User;

/**
 * Read-only access to llx_user, on the ORM: every value is bound, every column goes through the User mapping or
 * a join declared here. Secret columns are never selected; api_key appears in a WHERE only (apiKeyMatches()), and
 * as the ApiKey column of the list when asked for (search() $withApiKey).
 *
 * @extends Repository<User>
 */
final class UserRepository extends Repository implements EntityRepository
{
	/** Computed in SQL so the API key value itself is never loaded. */
	public const HAS_API_KEY_SQL = "CASE WHEN u.api_key IS NOT NULL AND u.api_key <> '' THEN 1 ELSE 0 END";

	/** Computed in SQL so the LDAP SID itself is never loaded. */
	public const IS_LDAP_MANAGED_SQL = "CASE WHEN u.ldap_sid IS NOT NULL AND u.ldap_sid <> '' THEN 1 ELSE 0 END";

	/** The last change of the user or of its extra fields (list.php l.403). */
	public const DATE_MODIFICATION_SQL = 'GREATEST(u.tms, COALESCE(ef.tms, u.tms))';

	/** The columns of the User mapping, in its order (MappingReaderTest checks they are the same). */
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

	private const ALIAS = 'u';

	/** The joined columns of the list (list.php l.404-406), the code names them. */
	private const LIST_JOINED_COLUMNS = [
		'u2.rowid AS id2', 'u2.login AS login2', 'u2.firstname AS firstname2', 'u2.lastname AS lastname2',
		'u2.admin AS admin2', 'u2.fk_soc AS fk_soc2', 'u2.office_phone AS ofice_phone2', 'u2.user_mobile AS user_mobile2',
		'u2.email AS email2', 'u2.gender AS gender2', 'u2.photo AS photo2', 'u2.entity AS entity2', 'u2.statut AS status2',
		's.nom AS name', 's.canvas', 'co.code AS country_code', 'co.label AS country_label',
	];

	/**
	 * The sort fields of UserSearch::SORTABLE that are not a column of the mapping or of a declared join: ordered by
	 * the alias of a computed column. u.api_key is ordered by the has_api_key flag: the secret never reaches the
	 * ORDER BY (the keys without value first in ASC, as the legacy sort on u.api_key).
	 */
	private const COMPUTED_SORTS = ['date_modification' => 'date_modification', 'u.import_key' => 'import_key', 'u.api_key' => 'has_api_key'];

	/** An extra field column (ef.<name>) declared on the join, so that the list may be sorted on it. */
	private const EXTRA_FIELD_COLUMN = '/^[a-z_][a-z0-9_]*$/D';

	protected string $entityClass = User::class;

	private readonly Hydrator $hydrator;

	/**
	 * @param Orm|null        $orm        The ORM of the request (UserModule); null builds one on $connection with the
	 *                                    legacy entity scope (tests, scripts)
	 * @param Translator|null $translator The decimal and thousand separators of the natural search; null reads the
	 *                                    legacy $langs (tests, scripts)
	 * @throws \InvalidArgumentException When $orm runs on another connection
	 */
	public function __construct(
		Connection $connection,
		private readonly int $entity,
		?Orm $orm = null,
		private readonly ?Translator $translator = null
	) {
		if ($orm !== null && $orm->connection() !== $connection) {
			throw new \InvalidArgumentException('UserRepository: the Orm runs on another connection');
		}
		$this->hydrator = new Hydrator();
		parent::__construct($orm ?? new Orm($connection, new MappingRegistry(), new LegacyEntityScope(), $this->hydrator, new Snapshots($this->hydrator)));
	}

	/**
	 * The user of the entity or a superadmin (entity 0): 'entity IN (0, <entity>)', not the entity scope.
	 */
	public function find(int $id): ?User
	{
		return $this->first($this->cardQuery()->where('id', $id));
	}

	/**
	 * The same filter as find(), the user of the entity before the superadmin of the same login.
	 */
	public function findByLogin(string $login): ?User
	{
		return $this->first($this->cardQuery()->where('login', $login)->orderBy('entity', 'DESC'));
	}

	/**
	 * Number of users of the entity, superadmins (entity 0) included: getEntity('user') without multicompany.
	 */
	public function countInEntity(): int
	{
		return $this->query(self::ALIAS)->withoutEntityScope()->whereIn('entity', [0, $this->entity])->count();
	}

	/**
	 * The count card.php l.300-301 checks against main_limit_users: \User::getNbOfUsers('active'), the
	 * active users of getEntity('user', 0), which is "0,<entity>" without multicompany. With multicompany,
	 * the legacy count is the one of the multicompany module (getEntity() delegates to it): not reproduced.
	 */
	public function countForQuota(): int
	{
		return $this->query(self::ALIAS)->withoutEntityScope()->whereIn('entity', [0, $this->entity])->where('status', 1)->count();
	}

	/**
	 * The user list, the query of htdocs/user/list.php l.398-559: the same SELECT (plus the columns the User entity
	 * needs), joins, criteria, count and order, $limit + 1 rows read to know whether a next page exists.
	 *
	 * The criteria are bound: natural_search() and dol_natural_search_phone() through NaturalSearch, the entities
	 * through the entity scope (getEntity('user')), the IN () lists (status, supervisor, country) rebuilt from the
	 * integers of the criterion as list.php's IN ($db->sanitize(...)) but never invalid: a hostile value ('1,,2',
	 * '1) OR (1') cannot make the query fail (Review Focus 1). The sort is UserSearch's (its SORTABLE list), each
	 * field a column of the mapping or of a declared join (Query::isSortable()) or a computed one (COMPUTED_SORTS);
	 * anything else sorts on u.login.
	 *
	 * @param ?list<int>                                            $childIds          Only these users (the employee list
	 *                                                                                 without salaries->readall, l.524-526), null for all
	 * @param ?int                                                  $externalCompanyId The company of an external current user (l.434-436)
	 * @param array{select?: string, from?: string, where?: string} $fragments         Legacy SQL added at the places list.php
	 *                                                                                 adds it (Query::legacyFragment()): the extrafields columns and
	 *                                                                                 the resPrint of printFieldListSelect (l.408-417), printFieldListFrom
	 *                                                                                 (l.428-432), the extrafields criteria and printFieldListWhere (l.528-532)
	 * @param bool                                                  $withApiKey        Select the (encrypted) api_key, for the ApiKey column
	 * @throws \Dolibarr\Core\Database\DatabaseException
	 */
	public function search(UserSearch $search, ?array $childIds, ?int $externalCompanyId, array $fragments = [], bool $withApiKey = false): UserPage
	{
		$naturalSearch = $this->naturalSearch($search);

		$total = -1;
		if ($search->countTotal) {
			// l.538-539: the same query counted, without its select (nor DISTINCT) nor a GROUP BY of the where fragment
			$counted = $fragments;
			$counted['where'] = (string) preg_replace('/GROUP BY .*$/', '', $fragments['where'] ?? '');
			$total = $this->listQuery($search, $childIds, $externalCompanyId, $counted, $naturalSearch)->count();
			if ($search->offset() > $total) {
				$search = $search->firstPage();
			}
		}

		$list = $this->listQuery($search, $childIds, $externalCompanyId, $fragments, $naturalSearch)->distinct();
		$this->selectEntityColumns($list);
		$list->selectRaw(self::HAS_API_KEY_SQL, 'has_api_key')->selectRaw(self::IS_LDAP_MANAGED_SQL, 'is_ldap_managed');
		if ($withApiKey) {
			$this->selectApiKey($list);
		}
		$list->select('u.statut AS status')
			->selectRaw('u.import_key', 'import_key')
			->selectRaw(self::DATE_MODIFICATION_SQL, 'date_modification')
			->select('u.datec AS date_creation', ...self::LIST_JOINED_COLUMNS);
		$select = (string) preg_replace('/,\s*$/', '', $fragments['select'] ?? '');
		if ($select !== '') {
			$list->legacyFragment(select: $select);
		}
		$this->orderList($list, $search);

		$legacySql = $list->toLegacySql($this->orm->connection()->legacy());
		$rows = $list->limit($search->limit + 1)->offset($search->offset())->rows();
		$hasMore = count($rows) > $search->limit;
		$items = [];
		foreach (array_slice($rows, 0, $search->limit) as $row) {
			$items[] = $this->listRow($row);
		}

		return new UserPage($items, $total, $search->page, $search->limit, $hasMore, $legacySql, $this->selectPart($legacySql));
	}

	/**
	 * The query of find() and findByLogin(): the mapped columns and the computed flags, 'entity IN (0, <entity>)'
	 * of today instead of the entity scope.
	 *
	 * @return Query<User>
	 */
	private function cardQuery(): Query
	{
		$query = $this->query(self::ALIAS)->withoutEntityScope();
		$this->selectEntityColumns($query);

		return $query->selectRaw(self::HAS_API_KEY_SQL, 'has_api_key')
			->selectRaw(self::IS_LDAP_MANAGED_SQL, 'is_ldap_managed')
			->whereIn('entity', [0, $this->entity]);
	}

	/**
	 * @param Query<User> $query
	 */
	private function first(Query $query): ?User
	{
		$rows = $query->limit(1)->rows();

		return $rows === [] ? null : $this->user($rows[0]);
	}

	/**
	 * @param Query<User> $query
	 */
	private function selectEntityColumns(Query $query): void
	{
		$query->select(...array_map(static fn (string $column): string => self::ALIAS.'.'.$column, self::COLUMNS));
	}

	/**
	 * The FROM, joins and criteria of the list (list.php l.420-532), in the order list.php writes them.
	 *
	 * @param ?list<int>                                            $childIds
	 * @param array{select?: string, from?: string, where?: string} $fragments
	 * @return Query<User>
	 */
	private function listQuery(UserSearch $search, ?array $childIds, ?int $externalCompanyId, array $fragments, NaturalSearch $naturalSearch): Query
	{
		$str = static fn (string $key): string => $search->filter($key);
		$query = $this->joined($this->query(self::ALIAS), $search);
		if (($fragments['from'] ?? '') !== '') {
			$query->legacyFragment(from: $fragments['from']);
		}

		if ($externalCompanyId !== null && $externalCompanyId > 0) {
			$query->where('companyId', $externalCompanyId);
		}
		if (UserSearch::positive($str('search_supervisor'))) {
			$query->whereIn('supervisorId', self::integers($str('search_supervisor')));
		}
		$naturalSearch->apply($query, ['s.nom'], $str('search_thirdparty'));
		if (UserSearch::positive($str('search_warehouse'))) {
			$naturalSearch->apply($query, ['u.fk_warehouse'], $str('search_warehouse'));
		}
		$naturalSearch->apply($query, 'u.rowid', $str('search_rowid'), 1);
		foreach (['search_login' => 'u.login', 'search_lastname' => 'u.lastname', 'search_firstname' => 'u.firstname'] as $key => $column) {
			$naturalSearch->apply($query, $column, $str($key));
		}
		$gender = $str('search_gender');
		if ($gender !== '' && $gender !== '-1') {
			// Not natural_search(): %man% would also find woman (l.460)
			$query->where('gender', $gender);
		}
		$employee = $str('search_employee');
		if (is_numeric($employee) && $employee >= 0) {
			// The integer of list.php (u.employee = 2 finds nobody), not the boolean cast of the column
			$query->whereRaw($query->column('employee').' = :employee', ['employee' => (int) $employee]);
		}
		$naturalSearch->apply($query, 'u.accountancy_code', $str('search_accountancy_code'));
		$naturalSearch->applyPhone($query, 'u.office_phone', $str('search_phonepro'));
		$naturalSearch->applyPhone($query, 'u.user_mobile', $str('search_phonemobile'));
		$naturalSearch->apply($query, 'u.email', $str('search_email'));
		if ($str('search_country') !== '') {
			$query->whereIn('countryId', self::integers($str('search_country')));
		}
		if (trim($str('search_api_key')) !== '') {
			$this->apiKeyMatches($query, $str('search_api_key'));
		}
		$naturalSearch->apply($query, ['u.job'], $str('search_job'));
		$status = $str('search_status');
		if ($status !== '' && (!is_numeric($status) || (int) $status >= 0)) {
			$query->whereIn('status', self::integers($status));
		}
		$this->searchAll($query, $search, $naturalSearch);
		$this->category($query, (int) $str('search_categ'), $search->filters['search_category_user_operator'] ?? 0);
		if (UserSearch::positive($str('search_warehouse'))) {
			$query->where('warehouseId', (int) $str('search_warehouse'));
		}
		if ($childIds !== null) {
			$query->whereIn('id', array_map('intval', $childIds));
		}
		if (($fragments['where'] ?? '') !== '') {
			$query->legacyFragment(where: $fragments['where']);
		}

		return $query;
	}

	/**
	 * The joins of list.php l.421-425, their sortable columns declared: co.label, the extra fields date and the
	 * extra fields the sort field names (UserSearch has checked them against the extra fields of the list).
	 *
	 * @param Query<User> $query
	 * @return Query<User>
	 */
	private function joined(Query $query, UserSearch $search): Query
	{
		$extraFields = ['tms'];
		foreach (explode(',', $search->sortField) as $part) {
			if (str_starts_with($part, 'ef.') && preg_match(self::EXTRA_FIELD_COLUMN, substr($part, 3)) === 1) {
				$extraFields[] = substr($part, 3);
			}
		}

		return $query->leftJoin('user_extrafields', 'ef', 'u.rowid', 'ef.fk_object', array_values(array_unique($extraFields)))
			->leftJoin('societe', 's', 'u.fk_soc', 's.rowid', ['nom', 'canvas'])
			->leftJoin('user', 'u2', 'u.fk_user', 'u2.rowid')
			->leftJoin('c_country', 'co', 'u.fk_country', 'co.rowid', ['code', 'label']);
	}

	/**
	 * search_all (list.php l.488-490): natural_search() on $fieldstosearchall; u.api_key, when the api module adds it,
	 * through apiKeyMatches() OR the other columns.
	 *
	 * @param Query<User> $query
	 */
	private function searchAll(Query $query, UserSearch $search, NaturalSearch $naturalSearch): void
	{
		$value = $search->filter('search_all');
		$columns = array_keys($search->searchAllFields);
		$withApiKey = in_array('u.api_key', $columns, true);
		$columns = array_values(array_diff($columns, ['u.api_key']));
		if (!$withApiKey) {
			$naturalSearch->apply($query, $columns, $value);

			return;
		}
		// Nothing searchable ('|', '""'): no condition at all, as without the api module (Task 8 ruling)
		$probe = $this->joined($this->query(self::ALIAS)->withoutEntityScope(), $search);
		$unfiltered = $probe->compile()->sql;
		if ($naturalSearch->apply($probe, $columns, $value)->compile()->sql === $unfiltered) {
			return;
		}
		$query->where(function (Query $group) use ($naturalSearch, $columns, $value): void {
			$naturalSearch->apply($group, $columns, $value);
			$this->apiKeyMatches($group, $value, true);
		});
	}

	/**
	 * The only criterion on the secret api_key column (search_api_key, list.php l.475, and search_all with the api
	 * module, l.130): a bound LIKE (ILIKE on PostgreSQL, as the legacy LIKE converted) on the stored, encrypted,
	 * value, '*' as '%'. A filter only: the value is never selected by it. Unlike natural_search(), the whole value
	 * is one pattern: spaces, '|' and '!' are not operators there (NaturalSearch refuses secret columns).
	 *
	 * @param Query<User> $query
	 */
	private function apiKeyMatches(Query $query, string $value, bool $orWhere = false): void
	{
		$operator = $this->orm->connection()->dialect() === 'pgsql' ? 'ILIKE' : 'LIKE';
		$sql = self::ALIAS.'.api_key '.$operator.' :api_key_pattern';
		$params = ['api_key_pattern' => '%'.str_replace('*', '%', trim($value)).'%'];
		if ($orWhere) {
			$query->orWhereRaw($sql, $params);
		} else {
			$query->whereRaw($sql, $params);
		}
	}

	/**
	 * The encrypted api_key of the ApiKey column (list.php l.400, admin only, decrypted by UserListChrome): a legacy
	 * select fragment, the one route Query leaves for a secret column; never hydrated, never exposed (UserListRow).
	 *
	 * @param Query<User> $query
	 */
	private function selectApiKey(Query $query): void
	{
		$query->legacyFragment(select: ', '.self::ALIAS.'.api_key');
	}

	/**
	 * list.php l.492-520 (with l.493: only search_categ is used): -2 is "without tag", an id a tag; the operator
	 * 1 asks IN instead of =. EXISTS (SELECT ck.fk_user FROM llx_categorie_user ck WHERE ck.fk_user = u.rowid …),
	 * here through the user of the link (cu, its own rowid) since a subquery is a Query of an entity.
	 *
	 * @param Query<User> $query
	 */
	private function category(Query $query, int $category, mixed $operator): void
	{
		if ($category !== -2 && $category <= 0) {
			return;
		}
		$links = $this->query('cu')->withoutEntityScope()
			->join('categorie_user', 'ck', 'ck.fk_user', 'cu.rowid', ['fk_user', 'fk_categorie'])
			->select('ck.fk_user')
			->whereRaw('cu.rowid = '.self::ALIAS.'.rowid');
		if ($category === -2) {
			$query->whereNotExists($links);

			return;
		}
		if ((new IntCast(false))->fromDatabase($operator) === 0) {
			$links->where('ck.fk_categorie', $category);
		} else {
			$links->whereIn('ck.fk_categorie', [$category]);
		}
		$query->whereExists($links);
	}

	/**
	 * @param Query<User> $query
	 */
	private function orderList(Query $query, UserSearch $search): void
	{
		$fields = explode(',', $search->sortField);
		foreach ($fields as $field) {
			if (!isset(self::COMPUTED_SORTS[$field]) && !$query->isSortable($field)) {
				$fields = ['u.login'];
				break;
			}
		}
		foreach ($fields as $field) {
			$query->orderBy(self::COMPUTED_SORTS[$field] ?? $field, $search->sortOrder);
		}
	}

	/**
	 * The NaturalSearch of list.php's language: its decimal and thousand separators (natural_search(), price2num())
	 * and, for a phone criterion only, the trunk prefixes of c_country (dol_get_all_trunk_prefixes()).
	 */
	private function naturalSearch(UserSearch $search): NaturalSearch
	{
		$translator = $this->translator ?? new LegacyTranslator(LegacyContext::fromGlobals()->langs);
		$prefixes = [];
		if (trim($search->filter('search_phonepro')) !== '' || trim($search->filter('search_phonemobile')) !== '') {
			$rows = $this->orm->connection()->fetchAll("SELECT DISTINCT trunk_prefix FROM @c_country WHERE trunk_prefix IS NOT NULL AND trunk_prefix <> ''");
			foreach ($rows as $row) {
				$prefixes[] = (new StringCast(false))->fromDatabase($row['trunk_prefix'] ?? '');
			}
		}

		return new NaturalSearch($translator->trans('SeparatorDecimal'), $translator->transnoentities('SeparatorThousand'), $prefixes);
	}

	/**
	 * The integers of a comma list, as list.php's IN ($db->sanitize(...)) keeps them (an empty list matches nothing).
	 *
	 * @return list<int>
	 */
	private static function integers(string $list): array
	{
		$ids = [];
		foreach (explode(',', $list) as $part) {
			$part = trim($part);
			if (preg_match('/^-?\d+$/', $part) === 1) {
				$ids[] = (int) $part;
			}
		}

		return $ids;
	}

	/**
	 * $sqlfields of list.php: the SELECT of the legacy SQL of the list, up to its FROM (written by the code: the
	 * select fragments come before it).
	 */
	private function selectPart(string $legacySql): string
	{
		$prefix = $this->orm->connection()->prefix();
		$position = strpos($legacySql, ' FROM '.$prefix.'user AS '.self::ALIAS.' LEFT JOIN '.$prefix.'user_extrafields AS ef ON ');

		return $position === false ? '' : substr($legacySql, 0, $position);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function listRow(array $row): UserListRow
	{
		$text = new StringCast(true);
		$legacyRow = [];
		foreach ($row as $column => $value) {
			// The $obj of list.php: what DoliDB::fetch_object() gives, strings and nulls
			$legacyRow[$column] = is_scalar($value) && !is_string($value) ? $text->fromDatabase($value) : $value;
		}

		return new UserListRow(
			$this->user($row),
			$text->fromDatabase($row['login2'] ?? null),
			$text->fromDatabase($row['firstname2'] ?? null),
			$text->fromDatabase($row['lastname2'] ?? null),
			$text->fromDatabase($row['name'] ?? null),
			$text->fromDatabase($row['country_code'] ?? null),
			$text->fromDatabase($row['country_label'] ?? null),
			(new DateCast(DateCast::DATETIME))->fromDatabase($row['date_modification'] ?? null),
			$legacyRow
		);
	}

	/**
	 * The User of a row: its mapped columns cast by the mapping, the two flags computed in SQL.
	 *
	 * @param array<string, mixed> $row
	 * @throws \LogicException When the row lacks a mapped column
	 */
	private function user(array $row): User
	{
		$flag = new IntCast(false);
		$user = $this->hydrator->hydrate($this->mapping(), $row, [
			'hasApiKey' => $flag->fromDatabase($row['has_api_key'] ?? 0) === 1,
			'isLdapManaged' => $flag->fromDatabase($row['is_ldap_managed'] ?? 0) === 1,
		]);
		if (!$user instanceof User) {
			throw new \LogicException('UserRepository: the mapping built a '.$user::class);
		}

		return $user;
	}
}
