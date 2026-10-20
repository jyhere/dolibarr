<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Entity;

use Dolibarr\Core\Entity\HasNotes;
use Dolibarr\Core\View\Exposable;

/**
 * Read model of a Dolibarr user (llx_user). Secrets (password hashes, API key,
 * OpenID, LDAP SID) are never part of this entity.
 */
final class User implements Exposable, HasNotes
{
	/**
	 * @param array<string, mixed> $extraFields
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $entity,
		public readonly string $login,
		public readonly bool $admin = false,
		public readonly bool $employee = true,
		public readonly int $status = 1,
		public readonly ?string $lastname = null,
		public readonly ?string $firstname = null,
		public readonly ?string $gender = null,
		public readonly ?string $civility = null,
		public readonly ?string $job = null,
		public readonly ?string $email = null,
		public readonly ?string $officePhone = null,
		public readonly ?string $userMobile = null,
		public readonly ?string $photo = null,
		public readonly ?string $lang = null,
		public readonly ?string $color = null,
		public readonly ?\DateTimeImmutable $dateStartValidity = null,
		public readonly ?\DateTimeImmutable $dateEndValidity = null,
		public readonly bool $hasApiKey = false,
		public readonly ?string $personalEmail = null,
		public readonly ?string $personalMobile = null,
		public readonly ?string $address = null,
		public readonly ?string $zip = null,
		public readonly ?string $town = null,
		public readonly ?int $stateId = null,
		public readonly ?int $countryId = null,
		public readonly ?\DateTimeImmutable $birth = null,
		public readonly ?string $birthPlace = null,
		public readonly ?string $signature = null,
		public readonly ?\DateTimeImmutable $dateLastLogin = null,
		public readonly ?\DateTimeImmutable $datePreviousLogin = null,
		public readonly ?string $notePublic = null,
		public readonly ?string $notePrivate = null,
		public readonly ?int $companyId = null,
		public readonly ?int $contactId = null,
		public readonly ?int $memberId = null,
		public readonly ?int $supervisorId = null,
		public readonly ?int $expenseValidatorId = null,
		public readonly ?int $holidayValidatorId = null,
		public readonly ?string $refEmployee = null,
		public readonly ?string $refExt = null,
		public readonly ?\DateTimeImmutable $dateCreated = null,
		public readonly ?\DateTimeImmutable $dateModified = null,
		public readonly ?float $salary = null,
		public readonly ?float $salaryExtra = null,
		public readonly ?float $thm = null,
		public readonly ?float $tjm = null,
		public readonly ?float $weeklyHours = null,
		public readonly ?\DateTimeImmutable $dateEmployment = null,
		public readonly ?\DateTimeImmutable $dateEmploymentEnd = null,
		public readonly array $extraFields = []
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
		];
	}

	private static function iso(?\DateTimeImmutable $d): ?string
	{
		return $d?->format(DATE_ATOM);
	}
}
