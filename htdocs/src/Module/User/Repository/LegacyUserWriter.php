<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Repository;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Service\ValidationException;

/**
 * @internal legacy adapter
 *
 * Writes users through the legacy \User methods, so that their checks, synchronisations
 * (member, contact, LDAP) and triggers keep running. The legacy messages, already translated,
 * become ValidationException messages.
 */
final class LegacyUserWriter implements UserWriter
{
	public function __construct(
		private readonly LegacyContext $ctx,
		private readonly LegacyObjectLoader $loader,
		private readonly Connection $db
	) {
	}

	/**
	 * user/card.php l.288-475 (action add): create() then the minor fields through its inner update().
	 */
	public function insert(User $user, int $creatorId): int
	{
		$legacyUser = new \User($this->ctx->db);
		$this->fill($legacyUser, $user);
		$legacyUser->entity = $user->entity;
		$legacyUser->array_options = $user->extraFields;

		$result = $legacyUser->create($this->actor($creatorId));
		if ($result <= 0) {
			$messages = self::messages($legacyUser);
			if ($result === -6 && $messages === []) {
				$this->ctx->langs->load('errors');
				$messages = [$this->ctx->langs->trans('ErrorLoginAlreadyExists', $user->login)];
			}
			throw new ValidationException($messages === [] ? [$this->ctx->langs->trans('Error')] : $messages);
		}

		return $result;
	}

	/**
	 * user/card.php l.497-745 (action update): fetch, oldcopy, properties, update().
	 * Only the mapped properties are replaced: the others keep their database value.
	 *
	 * The company and the contact stay the fetched ones, as on the card where the new link is the raw
	 * UPDATE that follows (linkToContact()): update() synchronises the contact the user was linked to,
	 * never the newly chosen one.
	 *
	 * A unique key refused by the database (DB_ERROR_RECORD_ALREADY_EXISTS, card.php l.674-677) gives
	 * ErrorUpdateCanceledDueToDuplicatedUniqueValue; the duplicate login that update() detects itself
	 * keeps its own message (ErrorLoginAlreadyExists), as on the card.
	 */
	public function update(User $user, int $actorId): void
	{
		$legacyUser = $this->fetch($user->id);
		$legacyUser->oldcopy = clone $legacyUser;
		$socid = $legacyUser->socid;
		$contactId = $legacyUser->contact_id;
		$this->fill($legacyUser, $user);
		$legacyUser->socid = $socid;
		$legacyUser->contact_id = $contactId;
		$legacyUser->entity = $user->entity;
		$legacyUser->array_options = array_merge(is_array($legacyUser->array_options) ? $legacyUser->array_options : [], $user->extraFields);

		if ($legacyUser->update($this->actor($actorId)) < 0) {
			// card.php tests $db->errno(), the code of the last statement (lasterrno() may be an older one)
			if ($this->ctx->db->errno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				$this->ctx->langs->load('users');
				throw new ValidationException([$this->ctx->langs->trans('ErrorUpdateCanceledDueToDuplicatedUniqueValue', $user->login)]);
			}
			$this->fail($legacyUser);
		}
	}

	public function isCurrentPassword(int $id, string $clearPassword): bool
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
		$legacyUser = $this->fetch($id);

		return $clearPassword === (string) $legacyUser->pass_indatabase || dol_verifyHash($clearPassword, (string) $legacyUser->pass_indatabase_crypted);
	}

	/**
	 * user/card.php l.216-247: setstatus() returns 0 when the status is unchanged.
	 */
	public function setStatus(int $id, bool $enabled): void
	{
		$legacyUser = $this->fetch($id);
		if ($legacyUser->setstatus($enabled ? 1 : 0) < 0) {
			$this->fail($legacyUser);
		}
	}

	/**
	 * user/card.php l.250-284: delete() refetches the user and refuses one with HRM data.
	 */
	public function delete(int $id, int $actorId): void
	{
		$legacyUser = $this->fetch($id);
		$legacyUser->oldcopy = clone $legacyUser;
		if ($legacyUser->delete($this->actor($actorId)) < 0) {
			$this->ctx->langs->load('errors');
			throw new ValidationException(array_merge([$this->ctx->langs->trans('ErrorUserCannotBeDelete')], self::messages($legacyUser)));
		}
	}

	/**
	 * user/card.php l.776-797: setPassword() returns the clear password, 0 when no row was
	 * changed, or a negative int on error.
	 */
	public function setPassword(int $id, string $clearPassword, bool $changeLater, int $actorId): string
	{
		$legacyUser = $this->fetch($id);
		$result = $legacyUser->setPassword($this->actor($actorId), $clearPassword, $changeLater ? 1 : 0);
		if (is_int($result) && $result < 0) {
			$this->fail($legacyUser);
		}
		if (!is_string($result) || $result === '') {
			// card.php l.784 shows "ErrorFailedToSetNewPassword", a key no lang file defines
			throw new ValidationException([$this->ctx->langs->trans('Error')]);
		}

		return $result;
	}

	/**
	 * user/card.php l.787-791.
	 */
	public function sendPassword(int $id, string $clearPassword): void
	{
		$legacyUser = $this->fetch($id);
		$actor = $this->ctx->user ?? $legacyUser;
		if ($legacyUser->send_password($actor, $clearPassword) <= 0) {
			$this->fail($legacyUser);
		}
	}

	/**
	 * user/perms.php l.114-120.
	 */
	public function addRights(int $id, ?int $rightId, ?string $module, int $entity): void
	{
		$legacyUser = $this->fetch($id);
		if ($legacyUser->addrights($rightId ?? 0, $module ?? '', '', $entity) < 0) {
			$this->fail($legacyUser);
		}
	}

	/**
	 * user/perms.php l.142-148.
	 */
	public function delRights(int $id, ?int $rightId, ?string $module, int $entity): void
	{
		$legacyUser = $this->fetch($id);
		if ($legacyUser->delrights($rightId ?? 0, $module ?? '', '', $entity) < 0) {
			$this->fail($legacyUser);
		}
	}

	/**
	 * user/card.php l.477-497 (action addgroup).
	 */
	public function setInGroup(int $id, int $groupId, int $entity): void
	{
		$legacyUser = $this->fetch($id);
		if ($legacyUser->setInGroup($groupId, $entity) < 0) {
			$this->fail($legacyUser);
		}
	}

	/**
	 * user/card.php l.477-497 (action removegroup).
	 */
	public function removeFromGroup(int $id, int $groupId, int $entity): void
	{
		$legacyUser = $this->fetch($id);
		if ($legacyUser->removeFromGroup($groupId, $entity) < 0) {
			$this->fail($legacyUser);
		}
	}

	/**
	 * user/card.php l.454-458 and l.730-734.
	 */
	public function setCategories(int $id, array $categoryIds): void
	{
		$legacyUser = $this->fetch($id);
		if ($legacyUser->setCategories($categoryIds) < 0) {
			$this->fail($legacyUser);
		}
	}

	/**
	 * user/card.php l.653-685: \User::update() does not write fk_soc / fk_socpeople, the card
	 * runs this raw UPDATE. It is the only write SQL of the module, reproduced with bound values: a contact
	 * gives its company; a contact without company and a posted company clear the company.
	 */
	public function linkToContact(int $id, int $contactId, int $companyId): bool
	{
		$mismatch = false;
		$params = ['id' => $id];
		if ($contactId > 0) {
			$contactCompany = $this->db->fetchValue('SELECT fk_soc FROM @socpeople WHERE rowid = :contact', ['contact' => $contactId]);
			$contactCompany = is_numeric($contactCompany) ? (int) $contactCompany : 0;
			$sql = 'UPDATE @user SET fk_socpeople = :contact';
			$params['contact'] = $contactId;
			if ($contactCompany > 0) {
				$sql .= ', fk_soc = :company';
				$params['company'] = $contactCompany;
			} elseif ($companyId > 0) {
				$sql .= ', fk_soc = null';
				$mismatch = true;
			}
		} elseif ($companyId > 0) {
			$sql = 'UPDATE @user SET fk_socpeople = NULL, fk_soc = :company';
			$params['company'] = $companyId;
		} else {
			$sql = 'UPDATE @user SET fk_socpeople = NULL, fk_soc = NULL';
		}
		try {
			$this->db->execute($sql.' WHERE rowid = :id', $params);
		} catch (DatabaseException) {
			// card.php l.680-683: the database message (e.g. uk_user_fk_socpeople, a contact already linked to a user)
			throw new ValidationException([$this->ctx->db->lasterror()]);
		}

		return $mismatch;
	}

	/**
	 * user/perms.php l.123-128: own rights and menu reloaded after a change on oneself.
	 */
	public function reloadOwnRights(): void
	{
		$user = $this->ctx->user;
		if ($user === null) {
			return;
		}
		$user->clearrights();
		$user->loadRights();
		$menu = $this->ctx->menuManager();
		if ($menu !== null && method_exists($menu, 'loadMenu')) {
			$menu->loadMenu();
		}
	}

	/**
	 * actions_changeselectedfields.inc.php l.33-48: dol_set_user_param() on the current user, which also updates
	 * his $user->conf for the rest of the request.
	 */
	public function saveListFields(string $varpage, string $fields): void
	{
		$user = $this->ctx->user;
		if ($user === null) {
			throw new ValidationException([$this->ctx->langs->trans('Error')]);
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		if (dol_set_user_param($this->ctx->db, $this->ctx->conf, $user, ['MAIN_SELECTEDFIELDS_'.$varpage => $fields]) < 0) {
			throw new ValidationException([$this->ctx->langs->trans('Error')]);
		}
	}

	/**
	 * Copies the entity onto the legacy properties that user/card.php sets before create()/update().
	 * Dates become Unix timestamps ('' when empty), as dol_mktime() gives them to the card.
	 */
	private function fill(\User $legacyUser, User $user): void
	{
		$legacyUser->login = $user->login;
		$legacyUser->lastname = $user->lastname ?? '';
		$legacyUser->firstname = $user->firstname ?? '';
		$legacyUser->admin = $user->admin ? 1 : 0;
		$legacyUser->employee = $user->employee ? 1 : 0;
		$legacyUser->gender = $user->gender ?? '';
		$legacyUser->civility_code = $user->civility ?? '';
		$legacyUser->job = $user->job ?? '';
		$legacyUser->email = $user->email ?? '';
		$legacyUser->personal_email = $user->personalEmail ?? '';
		$legacyUser->office_phone = $user->officePhone ?? '';
		$legacyUser->office_fax = $user->officeFax ?? '';
		$legacyUser->user_mobile = $user->userMobile ?? '';
		$legacyUser->personal_mobile = $user->personalMobile ?? '';
		$legacyUser->address = $user->address ?? '';
		$legacyUser->zip = $user->zip ?? '';
		$legacyUser->town = $user->town ?? '';
		$legacyUser->state_id = $user->stateId ?? 0;
		$legacyUser->country_id = $user->countryId ?? 0;
		$legacyUser->socid = $user->companyId;
		$legacyUser->contact_id = $user->contactId;
		$legacyUser->fk_member = $user->memberId;
		$legacyUser->fk_user = $user->supervisorId ?? 0;
		$legacyUser->fk_user_expense_validator = $user->expenseValidatorId ?? 0;
		$legacyUser->fk_user_holiday_validator = $user->holidayValidatorId ?? 0;
		$legacyUser->datestartvalidity = self::timestamp($user->dateStartValidity);
		$legacyUser->dateendvalidity = self::timestamp($user->dateEndValidity);
		$legacyUser->birth = self::timestamp($user->birth);
		$legacyUser->lang = $user->lang ?? '';
		$legacyUser->color = $user->color ?? '';
		$legacyUser->photo = $user->photo ?? '';
		$legacyUser->signature = $user->signature ?? '';
		$legacyUser->note_public = $user->notePublic ?? '';
		$legacyUser->note_private = $user->notePrivate ?? '';
		$legacyUser->ref_employee = $user->refEmployee ?? '';
		$legacyUser->ref_ext = $user->refExt ?? '';
		// '' writes NULL (card.php l.555-566); a null float would leave the column untouched
		$legacyUser->thm = self::decimal($user->thm);
		$legacyUser->tjm = self::decimal($user->tjm);
		$legacyUser->salary = self::decimal($user->salary);
		$legacyUser->salaryextra = self::decimal($user->salaryExtra);
		$legacyUser->weeklyhours = self::decimal($user->weeklyHours);
		$legacyUser->dateemployment = self::timestamp($user->dateEmployment);
		$legacyUser->dateemploymentend = self::timestamp($user->dateEmploymentEnd);
		$legacyUser->accountancy_code = $user->accountancyCode ?? '';
		$legacyUser->fk_warehouse = $user->warehouseId ?? 0;
		$legacyUser->national_registration_number = $user->nationalRegistrationNumber ?? '';
		// Reset by setPassword(): see User::$forcePasswordChange
		$legacyUser->force_pass_change = $user->forcePasswordChange ? 1 : 0;
	}

	/**
	 * @throws ValidationException When the user does not exist
	 */
	private function fetch(int $id): \User
	{
		$legacyUser = new \User($this->ctx->db);
		if ($id <= 0 || $legacyUser->fetch($id) <= 0) {
			$this->ctx->langs->load('errors');
			throw new ValidationException([$this->ctx->langs->trans('ErrorRecordNotFound')]);
		}

		return $legacyUser;
	}

	/**
	 * The legacy user making the change: the logged one when it is him, else loaded by id.
	 *
	 * @throws \LogicException When no user has this id
	 */
	private function actor(int $id): \User
	{
		if ($this->ctx->user !== null && (int) $this->ctx->user->id === $id) {
			return $this->ctx->user;
		}
		$actor = $this->loader->user($id);
		if ($actor === null) {
			throw new \LogicException('Unknown actor id '.$id);
		}

		return $actor;
	}

	/**
	 * @throws ValidationException Always
	 */
	private function fail(\User $legacyUser): never
	{
		$messages = self::messages($legacyUser);
		throw new ValidationException($messages === [] ? [$this->ctx->langs->trans('Error')] : $messages);
	}

	/**
	 * @return list<string>
	 */
	private static function messages(\User $legacyUser): array
	{
		$messages = [];
		foreach (array_merge([$legacyUser->error], $legacyUser->errors) as $message) {
			if (is_string($message) && $message !== '' && !in_array($message, $messages, true)) {
				$messages[] = $message;
			}
		}

		return $messages;
	}

	/**
	 * UserRepository reads the stored wall clock as UTC while the legacy writes timestamps with
	 * idate() in the server timezone: the wall clock is kept by reading it in the server timezone.
	 */
	private static function timestamp(?\DateTimeImmutable $date): int|string
	{
		if ($date === null) {
			return '';
		}

		return (new \DateTimeImmutable($date->format('Y-m-d H:i:s'), new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
	}

	private static function decimal(?float $value): string
	{
		return $value === null ? '' : (string) $value;
	}
}
