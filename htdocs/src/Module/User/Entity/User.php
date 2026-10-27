<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Entity;

use Dolibarr\Core\Entity\HasNotes;
use Dolibarr\Core\Orm\Attribute\Column;
use Dolibarr\Core\Orm\Attribute\Id;
use Dolibarr\Core\Orm\Attribute\Table;
use Dolibarr\Core\View\Exposable;

/**
 * Read model of a Dolibarr user (llx_user). Secrets (password hashes, API key,
 * OpenID, LDAP SID) are never part of this entity.
 *
 * Mapped on llx_user for the ORM (UserRepository). Not mapped: $hasApiKey and $isLdapManaged, flags computed in
 * SQL from secret columns the mapping never reads, and $extraFields (no #[ExtraFields]: the user list reads the
 * extra fields with the legacy helpers, as SQL fragments, and nothing reads them from the entity).
 *
 * forcePasswordChange is force_pass_change. Legacy quirk kept as is: \User::setPassword() resets it to 0,
 * so a flag written with a new password does not survive (the same on user/card.php).
 */
#[Table('user')]
final class User implements Exposable, HasNotes
{
	/**
	 * @param array<string, mixed> $extraFields
	 */
	public function __construct(
		#[Id] public readonly int $id,
		#[Column] public readonly int $entity,
		#[Column] public readonly string $login,
		#[Column] public readonly bool $admin = false,
		#[Column] public readonly bool $employee = true,
		#[Column('statut')] public readonly int $status = 1,
		#[Column] public readonly ?string $lastname = null,
		#[Column] public readonly ?string $firstname = null,
		#[Column] public readonly ?string $gender = null,
		#[Column] public readonly ?string $civility = null,
		#[Column] public readonly ?string $job = null,
		#[Column] public readonly ?string $email = null,
		#[Column] public readonly ?string $officePhone = null,
		#[Column] public readonly ?string $userMobile = null,
		#[Column] public readonly ?string $photo = null,
		#[Column] public readonly ?string $lang = null,
		#[Column] public readonly ?string $color = null,
		#[Column('datestartvalidity')] public readonly ?\DateTimeImmutable $dateStartValidity = null,
		#[Column('dateendvalidity')] public readonly ?\DateTimeImmutable $dateEndValidity = null,
		public readonly bool $hasApiKey = false,
		#[Column] public readonly ?string $personalEmail = null,
		#[Column] public readonly ?string $personalMobile = null,
		#[Column] public readonly ?string $address = null,
		#[Column] public readonly ?string $zip = null,
		#[Column] public readonly ?string $town = null,
		#[Column('fk_state')] public readonly ?int $stateId = null,
		#[Column('fk_country')] public readonly ?int $countryId = null,
		#[Column(cast: 'date')] public readonly ?\DateTimeImmutable $birth = null,
		#[Column] public readonly ?string $birthPlace = null,
		#[Column] public readonly ?string $signature = null,
		#[Column('datelastlogin')] public readonly ?\DateTimeImmutable $dateLastLogin = null,
		#[Column('datepreviouslogin')] public readonly ?\DateTimeImmutable $datePreviousLogin = null,
		#[Column] public readonly ?string $notePublic = null,
		#[Column] public readonly ?string $notePrivate = null,
		#[Column('fk_soc')] public readonly ?int $companyId = null,
		#[Column('fk_socpeople')] public readonly ?int $contactId = null,
		#[Column('fk_member')] public readonly ?int $memberId = null,
		#[Column('fk_user')] public readonly ?int $supervisorId = null,
		#[Column('fk_user_expense_validator')] public readonly ?int $expenseValidatorId = null,
		#[Column('fk_user_holiday_validator')] public readonly ?int $holidayValidatorId = null,
		#[Column] public readonly ?string $refEmployee = null,
		#[Column] public readonly ?string $refExt = null,
		#[Column('datec')] public readonly ?\DateTimeImmutable $dateCreated = null,
		#[Column('tms')] public readonly ?\DateTimeImmutable $dateModified = null,
		#[Column] public readonly ?float $salary = null,
		#[Column('salaryextra')] public readonly ?float $salaryExtra = null,
		#[Column] public readonly ?float $thm = null,
		#[Column] public readonly ?float $tjm = null,
		#[Column('weeklyhours')] public readonly ?float $weeklyHours = null,
		#[Column('dateemployment', 'date')] public readonly ?\DateTimeImmutable $dateEmployment = null,
		#[Column('dateemploymentend', 'date')] public readonly ?\DateTimeImmutable $dateEmploymentEnd = null,
		public readonly array $extraFields = [],
		#[Column] public readonly ?string $officeFax = null,
		#[Column] public readonly ?string $accountancyCode = null,
		#[Column('fk_warehouse')] public readonly ?int $warehouseId = null,
		#[Column] public readonly ?string $nationalRegistrationNumber = null,
		public readonly bool $isLdapManaged = false,
		#[Column('force_pass_change')] public readonly bool $forcePasswordChange = false
	) {
	}

	public function getPublicNote(): ?string
	{
		return $this->notePublic;
	}

	public function getPrivateNote(): ?string
	{
		return $this->notePrivate;
	}

	public function fullName(): string
	{
		return trim(($this->firstname ?? '').' '.($this->lastname ?? ''));
	}

	public function isEnabled(): bool
	{
		return $this->status === 1;
	}

	public function isExternal(): bool
	{
		return $this->companyId !== null && $this->companyId > 0;
	}

	/**
	 * Bounds are inclusive and compared at the date level; a null bound is open.
	 */
	public function isWithinValidityPeriod(\DateTimeImmutable $now): bool
	{
		$today = $now->format('Y-m-d');
		if ($this->dateStartValidity !== null && $today < $this->dateStartValidity->format('Y-m-d')) {
			return false;
		}
		if ($this->dateEndValidity !== null && $today > $this->dateEndValidity->format('Y-m-d')) {
			return false;
		}

		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(Visibility $for = Visibility::Public): array
	{
		$data = [
			'id' => $this->id,
			'entity' => $this->entity,
			'login' => $this->login,
			'admin' => $this->admin,
			'employee' => $this->employee,
			'status' => $this->status,
			'lastname' => $this->lastname,
			'firstname' => $this->firstname,
			'gender' => $this->gender,
			'civility' => $this->civility,
			'job' => $this->job,
			'email' => $this->email,
			'officePhone' => $this->officePhone,
			'officeFax' => $this->officeFax,
			'userMobile' => $this->userMobile,
			'photo' => $this->photo,
			'lang' => $this->lang,
			'color' => $this->color,
			'dateStartValidity' => self::iso($this->dateStartValidity),
			'dateEndValidity' => self::iso($this->dateEndValidity),
			'hasApiKey' => $this->hasApiKey,
		];
		if ($for === Visibility::Public) {
			return $data;
		}

		$data += [
			'personalEmail' => $this->personalEmail,
			'personalMobile' => $this->personalMobile,
			'address' => $this->address,
			'zip' => $this->zip,
			'town' => $this->town,
			'stateId' => $this->stateId,
			'countryId' => $this->countryId,
			'birth' => self::iso($this->birth),
			'birthPlace' => $this->birthPlace,
			'signature' => $this->signature,
			'dateLastLogin' => self::iso($this->dateLastLogin),
			'datePreviousLogin' => self::iso($this->datePreviousLogin),
			'notePublic' => $this->notePublic,
			'notePrivate' => $this->notePrivate,
		];
		if ($for === Visibility::SameUser) {
			return $data;
		}

		return $data + [
			'companyId' => $this->companyId,
			'contactId' => $this->contactId,
			'memberId' => $this->memberId,
			'supervisorId' => $this->supervisorId,
			'expenseValidatorId' => $this->expenseValidatorId,
			'holidayValidatorId' => $this->holidayValidatorId,
			'refEmployee' => $this->refEmployee,
			'refExt' => $this->refExt,
			'dateCreated' => self::iso($this->dateCreated),
			'dateModified' => self::iso($this->dateModified),
			'salary' => $this->salary,
			'salaryExtra' => $this->salaryExtra,
			'thm' => $this->thm,
			'tjm' => $this->tjm,
			'weeklyHours' => $this->weeklyHours,
			'dateEmployment' => self::iso($this->dateEmployment),
			'dateEmploymentEnd' => self::iso($this->dateEmploymentEnd),
			'accountancyCode' => $this->accountancyCode,
			'warehouseId' => $this->warehouseId,
			'nationalRegistrationNumber' => $this->nationalRegistrationNumber,
			'isLdapManaged' => $this->isLdapManaged,
			'forcePasswordChange' => $this->forcePasswordChange,
		];
	}

	private static function iso(?\DateTimeImmutable $date): ?string
	{
		return $date?->format(DATE_ATOM);
	}
}
