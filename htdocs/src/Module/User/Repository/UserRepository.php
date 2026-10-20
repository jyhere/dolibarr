<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Repository;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Module\User\Entity\User;

/**
 * Read-only access to llx_user. Secret columns are never selected.
 */
final class UserRepository
{
	/** Computed in SQL so the API key value itself is never loaded. */
	public const HAS_API_KEY_SQL = "CASE WHEN u.api_key IS NOT NULL AND u.api_key <> '' THEN 1 ELSE 0 END AS has_api_key";

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

	private function fetch(string $where, string $order = ''): ?User
	{
		$sql = 'SELECT '.implode(', ', array_map(static fn (string $col): string => 'u.'.$col, self::COLUMNS)).', '.self::HAS_API_KEY_SQL
			.' FROM '.$this->c->prefix().'user as u'
			.' WHERE '.$where.' AND u.entity IN (0, '.$this->entity.')'
			.$order.' LIMIT 1';
		$row = $this->c->fetchOne($sql);

		return $row === null ? null : $this->hydrate($row);
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
			dateEmploymentEnd: self::date($r['dateemploymentend'] ?? null)
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
