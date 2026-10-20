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
	public function insert(User $u, int $creatorId): int
	{
		$o = new \User($this->ctx->db);
		$this->fill($o, $u);
		$o->entity = $u->entity;
		$o->array_options = $u->extraFields;

		$result = $o->create($this->actor($creatorId));
		if ($result <= 0) {
			$messages = self::messages($o);
			if ($result === -6 && $messages === []) {
				$this->ctx->langs->load('errors');
				$messages = [$this->ctx->langs->trans('ErrorLoginAlreadyExists', $u->login)];
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
	public function update(User $u, int $actorId): void
	{
		$o = $this->fetch($u->id);
		$o->oldcopy = clone $o;
		$socid = $o->socid;
		$contactId = $o->contact_id;
		$this->fill($o, $u);
		$o->socid = $socid;
		$o->contact_id = $contactId;
		$o->entity = $u->entity;
		$o->array_options = array_merge(is_array($o->array_options) ? $o->array_options : [], $u->extraFields);

		if ($o->update($this->actor($actorId)) < 0) {
			// card.php tests $db->errno(), the code of the last statement (lasterrno() may be an older one)
			if ($this->ctx->db->errno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				$this->ctx->langs->load('users');
				throw new ValidationException([$this->ctx->langs->trans('ErrorUpdateCanceledDueToDuplicatedUniqueValue', $u->login)]);
			}
			$this->fail($o);
		}
	}

	public function isCurrentPassword(int $id, string $clearPassword): bool
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
		$o = $this->fetch($id);

		return $clearPassword === (string) $o->pass_indatabase || dol_verifyHash($clearPassword, (string) $o->pass_indatabase_crypted);
	}

	/**
	 * user/card.php l.216-247: setstatus() returns 0 when the status is unchanged.
	 */
	public function setStatus(int $id, bool $enabled): void
	{
		$o = $this->fetch($id);
		if ($o->setstatus($enabled ? 1 : 0) < 0) {
			$this->fail($o);
		}
	}

	/**
	 * user/card.php l.250-284: delete() refetches the user and refuses one with HRM data.
	 */
	public function delete(int $id, int $actorId): void
	{
		$o = $this->fetch($id);
		$o->oldcopy = clone $o;
		if ($o->delete($this->actor($actorId)) < 0) {
			$this->ctx->langs->load('errors');
			throw new ValidationException(array_merge([$this->ctx->langs->trans('ErrorUserCannotBeDelete')], self::messages($o)));
		}
	}

	/**
	 * user/card.php l.776-797: setPassword() returns the clear password, 0 when no row was
	 * changed, or a negative int on error.
	 */
	public function setPassword(int $id, string $clearPassword, bool $changeLater, int $actorId): string
	{
		$o = $this->fetch($id);
		$result = $o->setPassword($this->actor($actorId), $clearPassword, $changeLater ? 1 : 0);
		if (is_int($result) && $result < 0) {
			$this->fail($o);
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
		$o = $this->fetch($id);
		$actor = $this->ctx->user ?? $o;
		if ($o->send_password($actor, $clearPassword) <= 0) {
			$this->fail($o);
		}
	}

	/**
	 * user/perms.php l.114-120.
	 */
	public function addRights(int $id, ?int $rightId, ?string $module, int $entity): void
	{
		$o = $this->fetch($id);
		if ($o->addrights($rightId ?? 0, $module ?? '', '', $entity) < 0) {
			$this->fail($o);
		}
	}

	/**
	 * user/perms.php l.142-148.
	 */
	public function delRights(int $id, ?int $rightId, ?string $module, int $entity): void
	{
		$o = $this->fetch($id);
		if ($o->delrights($rightId ?? 0, $module ?? '', '', $entity) < 0) {
			$this->fail($o);
		}
	}

	/**
	 * user/card.php l.477-497 (action addgroup).
	 */
	public function setInGroup(int $id, int $groupId, int $entity): void
	{
		$o = $this->fetch($id);
		if ($o->setInGroup($groupId, $entity) < 0) {
			$this->fail($o);
		}
	}

	/**
	 * user/card.php l.477-497 (action removegroup).
	 */
	public function removeFromGroup(int $id, int $groupId, int $entity): void
	{
		$o = $this->fetch($id);
		if ($o->removeFromGroup($groupId, $entity) < 0) {
			$this->fail($o);
		}
	}

	/**
	 * user/card.php l.454-458 and l.730-734.
	 */
	public function setCategories(int $id, array $categoryIds): void
	{
		$o = $this->fetch($id);
		if ($o->setCategories($categoryIds) < 0) {
			$this->fail($o);
		}
	}

	/**
	 * user/card.php l.653-685: \User::update() does not write fk_soc / fk_socpeople, the card
	 * runs this raw UPDATE. It is the only write SQL of the module, reproduced as is: a contact
	 * gives its company; a contact without company and a posted company clear the company.
	 */
	public function linkToContact(int $id, int $contactId, int $companyId): void
	{
		$table = $this->db->prefix().'user';
		if ($contactId > 0) {
			$row = $this->db->fetchOne('SELECT fk_soc FROM '.$this->db->prefix().'socpeople WHERE rowid = '.$contactId);
			$contactCompany = $row === null ? 0 : (int) (is_numeric($fk = get_object_vars($row)['fk_soc'] ?? null) ? $fk : 0);
			$sql = 'UPDATE '.$table.' SET fk_socpeople = '.$contactId;
			if ($contactCompany > 0) {
				$sql .= ', fk_soc = '.$contactCompany;
			} elseif ($companyId > 0) {
				$sql .= ', fk_soc = null';
			}
		} elseif ($companyId > 0) {
			$sql = 'UPDATE '.$table.' SET fk_socpeople = NULL, fk_soc = '.$companyId;
		} else {
			$sql = 'UPDATE '.$table.' SET fk_socpeople = NULL, fk_soc = NULL';
		}
		try {
			$this->db->execute($sql.' WHERE rowid = '.$id);
		} catch (DatabaseException) {
			// card.php l.680-683: the database message (e.g. uk_user_fk_socpeople, a contact already linked to a user)
			throw new ValidationException([$this->ctx->db->lasterror()]);
		}
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
	 * Copies the entity onto the legacy properties that user/card.php sets before create()/update().
	 * Dates become Unix timestamps ('' when empty), as dol_mktime() gives them to the card.
	 */
	private function fill(\User $o, User $u): void
	{
		$o->login = $u->login;
		$o->lastname = $u->lastname ?? '';
		$o->firstname = $u->firstname ?? '';
		$o->admin = $u->admin ? 1 : 0;
		$o->employee = $u->employee ? 1 : 0;
		$o->gender = $u->gender ?? '';
		$o->civility_code = $u->civility ?? '';
		$o->job = $u->job ?? '';
		$o->email = $u->email ?? '';
		$o->personal_email = $u->personalEmail ?? '';
		$o->office_phone = $u->officePhone ?? '';
		$o->office_fax = $u->officeFax ?? '';
		$o->user_mobile = $u->userMobile ?? '';
		$o->personal_mobile = $u->personalMobile ?? '';
		$o->address = $u->address ?? '';
		$o->zip = $u->zip ?? '';
		$o->town = $u->town ?? '';
		$o->state_id = $u->stateId ?? 0;
		$o->country_id = $u->countryId ?? 0;
		$o->socid = $u->companyId;
		$o->contact_id = $u->contactId;
		$o->fk_member = $u->memberId;
		$o->fk_user = $u->supervisorId ?? 0;
		$o->fk_user_expense_validator = $u->expenseValidatorId ?? 0;
		$o->fk_user_holiday_validator = $u->holidayValidatorId ?? 0;
		$o->datestartvalidity = self::timestamp($u->dateStartValidity);
		$o->dateendvalidity = self::timestamp($u->dateEndValidity);
		$o->birth = self::timestamp($u->birth);
		$o->lang = $u->lang ?? '';
		$o->color = $u->color ?? '';
		$o->photo = $u->photo ?? '';
		$o->signature = $u->signature ?? '';
		$o->note_public = $u->notePublic ?? '';
		$o->note_private = $u->notePrivate ?? '';
		$o->ref_employee = $u->refEmployee ?? '';
		$o->ref_ext = $u->refExt ?? '';
		// '' writes NULL (card.php l.555-566); a null float would leave the column untouched
		$o->thm = self::decimal($u->thm);
		$o->tjm = self::decimal($u->tjm);
		$o->salary = self::decimal($u->salary);
		$o->salaryextra = self::decimal($u->salaryExtra);
		$o->weeklyhours = self::decimal($u->weeklyHours);
		$o->dateemployment = self::timestamp($u->dateEmployment);
		$o->dateemploymentend = self::timestamp($u->dateEmploymentEnd);
		$o->accountancy_code = $u->accountancyCode ?? '';
		$o->fk_warehouse = $u->warehouseId ?? 0;
		$o->national_registration_number = $u->nationalRegistrationNumber ?? '';
		// Reset by setPassword(): see User::$forcePasswordChange
		$o->force_pass_change = $u->forcePasswordChange ? 1 : 0;
	}

	/**
	 * @throws ValidationException When the user does not exist
	 */
	private function fetch(int $id): \User
	{
		$o = new \User($this->ctx->db);
		if ($id <= 0 || $o->fetch($id) <= 0) {
			$this->ctx->langs->load('errors');
			throw new ValidationException([$this->ctx->langs->trans('ErrorRecordNotFound')]);
		}

		return $o;
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
	private function fail(\User $o): never
	{
		$messages = self::messages($o);
		throw new ValidationException($messages === [] ? [$this->ctx->langs->trans('Error')] : $messages);
	}

	/**
	 * @return list<string>
	 */
	private static function messages(\User $o): array
	{
		$messages = [];
		foreach (array_merge([$o->error], $o->errors) as $m) {
			if (is_string($m) && $m !== '' && !in_array($m, $messages, true)) {
				$messages[] = $m;
			}
		}

		return $messages;
	}

	/**
	 * UserRepository reads the stored wall clock as UTC while the legacy writes timestamps with
	 * idate() in the server timezone: the wall clock is kept by reading it in the server timezone.
	 */
	private static function timestamp(?\DateTimeImmutable $d): int|string
	{
		if ($d === null) {
			return '';
		}

		return (new \DateTimeImmutable($d->format('Y-m-d H:i:s'), new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
	}

	private static function decimal(?float $v): string
	{
		return $v === null ? '' : (string) $v;
	}
}
