<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Service;

use Dolibarr\Core\Config;
use Dolibarr\Core\Http\InputFilter;
use Dolibarr\Core\Http\Request;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Security\UserPermissionChecker;

/**
 * What the user card form posts, read with the fields and filters of htdocs/user/card.php (action add
 * l.287-452, action update l.478-773) and resolved against what the current user may edit: on an update a
 * field he may not edit already carries the value of the existing user.
 *
 * Not read, on purpose: api_key and openid (secrets: the spec forbids loading them into the entity, the legacy
 * page keeps that job, openid stays legacy-only), ldap_sid (computed as the isLdapManaged flag of the entity),
 * the social networks (deferred: the new form does not render them), the photo upload and deletephoto (files
 * are handled by the controller), and on an update note_public, note_private (card.php does not read them
 * there, they have their own page).
 *
 * Hardening over card.php: the posted admin flag is ignored unless the checker's canEditAdminFlag() allows it,
 * so a non-admin creator cannot make an admin, and an external target is never made admin. On an update, the
 * password, the login and the email are only read when canChangeCredentials() allows it (an admin's are not a
 * non-admin's to change, a superadmin's not an entity admin's), and the third party and contact only when
 * canLinkCompany() does (the form only shows them to an admin on another user, card.php l.2557).
 *
 * Only the fields of the rows the form shows are read (UserFormFields, the conditions the form is drawn with):
 * a row hidden by the setup (module off, option unset) keeps the stored value on an update, null (or the
 * default) on a creation; the tags are then not touched (categoryIds null).
 *
 * The extrafields come from ExtraFields::setOptionalsFromPost() (see InputFilter::optionals()): on an update
 * only the keys the form carries are returned and toEntity() merges them over the existing ones.
 *
 * The raw values are kept as GETPOST() returns them: the validator checks them, the writer escapes them.
 */
final class UserInput
{
	/**
	 * @param array<string, mixed> $extraFields 'options_<name>' => value
	 * @param list<int>|null       $categoryIds null when the tags row is not shown: the tags are left as they are
	 */
	public function __construct(
		public readonly ?string $lastname = null,
		public readonly string $login = '',
		public readonly ?string $firstname = null,
		public readonly ?string $gender = null,
		public readonly ?string $civility = null,
		public readonly ?string $lang = null,
		public readonly ?string $email = null,
		public readonly ?string $job = null,
		public readonly ?string $officePhone = null,
		public readonly ?string $officeFax = null,
		public readonly ?string $userMobile = null,
		public readonly ?string $address = null,
		public readonly ?string $zip = null,
		public readonly ?string $town = null,
		public readonly ?int $countryId = null,
		public readonly ?int $stateId = null,
		public readonly ?string $signature = null,
		public readonly ?string $notePublic = null,
		public readonly ?string $notePrivate = null,
		public readonly ?string $accountancyCode = null,
		public readonly ?string $refEmployee = null,
		public readonly ?string $color = null,
		public readonly bool $admin = false,
		public readonly bool $employee = false,
		public readonly ?int $supervisorId = null,
		public readonly ?int $expenseValidatorId = null,
		public readonly ?int $holidayValidatorId = null,
		public readonly ?int $warehouseId = null,
		public readonly ?float $salary = null,
		public readonly ?float $salaryExtra = null,
		public readonly ?float $thm = null,
		public readonly ?float $tjm = null,
		public readonly ?float $weeklyHours = null,
		public readonly ?\DateTimeImmutable $dateEmployment = null,
		public readonly ?\DateTimeImmutable $dateEmploymentEnd = null,
		public readonly ?\DateTimeImmutable $dateStartValidity = null,
		public readonly ?\DateTimeImmutable $dateEndValidity = null,
		public readonly ?\DateTimeImmutable $birth = null,
		public readonly array $extraFields = [],
		public readonly ?string $password = null,
		public readonly ?array $categoryIds = null,
		/** Update only: the posted contact (0 for none), null when the form does not carry contactid */
		public readonly ?int $contactId = null,
		/** Update only: the posted third party (0 for none), null when the form does not carry contactid */
		public readonly ?int $companyId = null,
		public readonly bool $forcePasswordChange = false,
		public readonly bool $superadmin = false,
		public readonly ?int $entity = null,
		public readonly ?string $nationalRegistrationNumber = null,
		public readonly ?string $extraFieldsError = null
	) {
	}

	/**
	 * @param User|null      $existing The user being edited, null on a creation
	 * @param UserFormFields $fields   The rows the form shows to the current user for that user
	 */
	public static function fromRequest(Request $r, UserPermissionChecker $perms, ?User $existing, Config $config, UserFormFields $fields): self
	{
		$update = $existing !== null;
		// A new user is asked to the checker as a user without id: it is not oneself, not external.
		$target = $existing ?? new User(0, $config->entity(), '');

		// The password: card.php l.463 (create) and l.511 (update, with the right to set it).
		$password = null;
		$credentials = $perms->canChangeCredentials($target);
		if (!$update || ($perms->canSetPassword($target) && $credentials)) {
			$password = self::text($r, 'password', 'password');
		}

		// l.311 and l.522: the ref employee is protected against deletion when it is not in the form.
		$refEmployee = $update && !$r->isSet('ref_employee') ? $existing->refEmployee : self::text($r, 'ref_employee');

		// l.392-414 and l.595-606: the superadmin flag and the entity are only meaningful to the controller.
		$entity = $r->isSet('entity') ? (int) $r->getPost('entity', 'int', 0) : null;

		// l.553-556 (update): the warehouse is only in the form with the stock module and MAIN_DEFAULT_WAREHOUSE_USER (l.1541).
		$warehouseId = $fields->warehouse ? self::id($r, 'fk_warehouse') : $existing?->warehouseId;

		// l.653 (update): the contact link (raw UPDATE of fk_socpeople/fk_soc) only runs when the form carries
		// contactid, which it only does for an admin on another user (l.2557). Null tells the caller not to touch
		// the link; toEntity() then keeps the stored ones.
		$contactId = $companyId = null;
		if ($update && $perms->canLinkCompany($target) && $r->isSet('contactid')) {
			$contactId = self::id($r, 'contactid') ?? 0;
			$companyId = self::id($r, 'socid') ?? 0;
		}

		$salaryAllowed = $perms->canSeeSalary($target);
		$money = static fn (string $name, ?float $current): ?float => $salaryAllowed ? self::money($r, $name) : $current;

		$color = $fields->color ? self::text($r, 'color') : null;
		$color = $color === null ? null : str_replace('#', '', $color);

		// l.311-314 and l.522-525: the national registration number is protected like the ref employee.
		$nationalNumber = $update && !$r->isSet('national_registration_number')
			? $existing->nationalRegistrationNumber
			: self::text($r, 'national_registration_number');

		$extra = $r->optionals('user', $update);
		$extraError = $extra[InputFilter::OPTIONALS_ERROR] ?? null;
		unset($extra[InputFilter::OPTIONALS_ERROR]);

		$categoryIds = null;
		if ($fields->categories) {
			$categories = $r->getPost('usercats', 'array:int', []);
			$categoryIds = is_array($categories) ? array_values(array_map('intval', $categories)) : [];
		}

		return new self(
			lastname: self::text($r, 'lastname') ?? '',
			login: $perms->canEditLogin($target, $existing->isLdapManaged ?? false) && $credentials ? (self::text($r, 'login') ?? '') : ($existing === null ? '' : $existing->login),
			firstname: self::text($r, 'firstname'),
			gender: self::text($r, 'gender', 'aZ09'),
			civility: $fields->civility ? self::text($r, 'civility_code', 'aZ09') : $existing?->civility,
			lang: $fields->language ? self::text($r, 'default_lang', 'aZ09') : $existing?->lang,
			email: $credentials ? self::email($r) : $existing?->email,
			job: self::text($r, 'job'),
			officePhone: self::text($r, 'office_phone'),
			officeFax: self::text($r, 'office_fax'),
			userMobile: self::text($r, 'user_mobile'),
			address: self::text($r, 'address'),
			zip: self::text($r, 'zipcode'),
			town: self::text($r, 'town'),
			countryId: (int) $r->getPost('country_id', 'int', 0),
			stateId: $fields->state ? (int) $r->getPost('state_id', 'int', 0) : $existing?->stateId,
			signature: self::text($r, 'signature', 'restricthtml'),
			notePublic: $update ? null : self::text($r, 'note_public', 'restricthtml'),
			notePrivate: $update ? null : self::text($r, 'note_private', 'restricthtml'),
			accountancyCode: $fields->accountancyCode ? self::text($r, 'accountancy_code') : $existing?->accountancyCode,
			refEmployee: $refEmployee,
			color: $fields->color ? $color : $existing?->color,
			admin: $perms->canEditAdminFlag($target) ? (bool) $r->getPost('admin', 'int', 0) : ($existing->admin ?? false),
			employee: (bool) $r->getPost('employee', 'int', 0),
			supervisorId: $fields->hierarchy ? self::id($r, 'fk_user') : $existing?->supervisorId,
			expenseValidatorId: $fields->expenseValidator ? self::id($r, 'fk_user_expense_validator') : $existing?->expenseValidatorId,
			holidayValidatorId: $fields->holidayValidator ? self::id($r, 'fk_user_holiday_validator') : $existing?->holidayValidatorId,
			warehouseId: $warehouseId,
			salary: $money('salary', $existing?->salary),
			salaryExtra: $money('salaryextra', $existing?->salaryExtra),
			thm: $money('thm', $existing?->thm),
			tjm: $money('tjm', $existing?->tjm),
			weeklyHours: $money('weeklyhours', $existing?->weeklyHours),
			dateEmployment: self::dateFromParts($r, 'dateemployment'),
			dateEmploymentEnd: self::dateFromParts($r, 'dateemploymentend'),
			dateStartValidity: self::dateFromParts($r, 'datestartvalidity'),
			dateEndValidity: self::dateFromParts($r, 'dateendvalidity'),
			birth: self::dateFromParts($r, 'dateofbirth'),
			extraFields: $extra,
			password: $password,
			categoryIds: $categoryIds,
			contactId: $contactId,
			companyId: $companyId,
			forcePasswordChange: $fields->forcePasswordChange ? (bool) $r->getPost('forcepasswordchange', 'int', 0) : ($existing->forcePasswordChange ?? false),
			superadmin: (bool) $r->getPost('superadmin', 'int', 0),
			entity: $entity,
			nationalRegistrationNumber: $nationalNumber,
			extraFieldsError: is_string($extraError) ? $extraError : null
		);
	}

	/**
	 * The user to write. $base is the existing user on an update (what the form does not carry is kept:
	 * status, API key flag, photo, member, dates of creation and login, notes...), null on a creation.
	 * $entity is the entity chosen by the caller, from $this->entity and $this->superadmin and the setup.
	 */
	public function toEntity(?User $base, int $entity): User
	{
		return new User(
			id: $base->id ?? 0,
			entity: $entity,
			login: $this->login,
			admin: $this->admin,
			employee: $this->employee,
			status: $base->status ?? 1,
			lastname: $this->lastname,
			firstname: $this->firstname,
			gender: $this->gender,
			civility: $this->civility,
			job: $this->job,
			email: $this->email,
			officePhone: $this->officePhone,
			userMobile: $this->userMobile,
			photo: $base?->photo,
			lang: $this->lang,
			color: $this->color,
			dateStartValidity: $this->dateStartValidity,
			dateEndValidity: $this->dateEndValidity,
			hasApiKey: $base->hasApiKey ?? false,
			personalEmail: $base?->personalEmail,
			personalMobile: $base?->personalMobile,
			address: $this->address,
			zip: $this->zip,
			town: $this->town,
			stateId: $this->stateId,
			countryId: $this->countryId,
			birth: $this->birth,
			birthPlace: $base?->birthPlace,
			signature: $this->signature,
			dateLastLogin: $base?->dateLastLogin,
			datePreviousLogin: $base?->datePreviousLogin,
			notePublic: $base === null ? $this->notePublic : $base->notePublic,
			notePrivate: $base === null ? $this->notePrivate : $base->notePrivate,
			// The link posted on an update, else the stored one (the writer never writes these two: linkToContact() does)
			companyId: $this->contactId === null ? $base?->companyId : ($this->companyId > 0 ? $this->companyId : null),
			contactId: $this->contactId === null ? $base?->contactId : ($this->contactId > 0 ? $this->contactId : null),
			memberId: $base?->memberId,
			supervisorId: $this->supervisorId,
			expenseValidatorId: $this->expenseValidatorId,
			holidayValidatorId: $this->holidayValidatorId,
			refEmployee: $this->refEmployee,
			refExt: $base?->refExt,
			dateCreated: $base?->dateCreated,
			dateModified: $base?->dateModified,
			salary: $this->salary,
			salaryExtra: $this->salaryExtra,
			thm: $this->thm,
			tjm: $this->tjm,
			weeklyHours: $this->weeklyHours,
			dateEmployment: $this->dateEmployment,
			dateEmploymentEnd: $this->dateEmploymentEnd,
			extraFields: $base === null ? $this->extraFields : array_merge($base->extraFields, $this->extraFields),
			officeFax: $this->officeFax,
			accountancyCode: $this->accountancyCode,
			warehouseId: $this->warehouseId,
			nationalRegistrationNumber: $this->nationalRegistrationNumber,
			isLdapManaged: $base->isLdapManaged ?? false,
			// card.php l.380 (add) and l.586 (update): the posted checkbox, 0 when it is absent
			forcePasswordChange: $this->forcePasswordChange
		);
	}

	/**
	 * dol_mktime(0, 0, 0, <name>month, <name>day, <name>year) of a date selector, at midnight UTC;
	 * null unless day, month and year are all given and make a real date (year 1 to 9999). An impossible
	 * date such as 30 February gives null, where legacy dol_mktime() normalises it; no message is raised.
	 */
	private static function dateFromParts(Request $r, string $name): ?\DateTimeImmutable
	{
		$day = $r->getInt($name.'day');
		$month = $r->getInt($name.'month');
		$year = $r->getInt($name.'year');
		if ($day <= 0 || $month <= 0 || $year <= 0 || $year > 9999 || !checkdate($month, $day, $year)) {
			return null;
		}

		return new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), new \DateTimeZone('UTC'));
	}

	/**
	 * A form text field, null when absent or empty.
	 */
	private static function text(Request $r, string $name, string $filter = 'alphanohtml'): ?string
	{
		$value = $r->getPost($name, $filter, '');

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * The email without any whitespace (card.php l.348).
	 */
	private static function email(Request $r): ?string
	{
		$value = self::text($r, 'email');
		$value = $value === null ? null : preg_replace('/\s+/', '', $value);

		return $value === null || $value === '' ? null : $value;
	}

	/**
	 * An id field: null unless positive (card.php l.362-364).
	 */
	private static function id(Request $r, string $name): ?int
	{
		$id = (int) $r->getPost($name, 'int', 0);

		return $id > 0 ? $id : null;
	}

	/**
	 * A money or hours field (GETPOSTFLOAT then price2num), null when empty (card.php l.371-380).
	 */
	private static function money(Request $r, string $name): ?float
	{
		if (self::text($r, $name) === null) {
			return null;
		}

		return (float) $r->getPost($name, 'float', 0.0);
	}
}
