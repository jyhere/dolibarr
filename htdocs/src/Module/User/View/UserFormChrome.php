<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\View;

use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Module\User\Entity\User;

/**
 * @internal legacy adapter
 *
 * Legacy-rendered fragments of the user card form (htdocs/user/card.php, create view l.992-1626 and
 * edit view l.2498-3309).
 * The only place of the module calling the legacy Form* helpers of that form; each fragment is
 * produced by the helper card.php calls, with the same arguments (line cited). A helper that prints
 * is captured (ob_start, try/finally); all of them go through capture(), which keeps both what is
 * printed and what is returned.
 *
 * Not rendered, on purpose: the api_key row (the new path never stores it, the legacy card keeps that
 * job, so the PARTIAL WORKAROUND hidden api_key of l.1355-1358 is not printed either), the social
 * networks rows (deferred, see UserInput), the LDAP import form (l.996-1063, action adduserldap stays
 * on card.php), and the entity row of the old multicompany modules without formObjectOptions
 * (l.1488-1499: the current module draws it from the formObjectOptions hook, run by the extrafields).
 *
 * Not rendered in the edit form either: the api_key row (l.2876-2889) and its generator script (l.3363-3365),
 * the OpenID row (l.2891-2902: secret-like, never loaded, kept on the legacy card; the new update leaves the
 * column untouched where card.php writes what is posted), the photo row (l.3076-3082: the upload and
 * deletephoto stay on the legacy card), the social networks rows (l.3011-3031), the old multicompany entity
 * row (l.3145-3157) and the clone confirmation card.php prints above the form (l.1722-1738, only opened by
 * the Clone button of the card). The stored clear password, shown masked by card.php to an actor who
 * cannot set it (l.2851), is never loaded: nothing is shown.
 */
final class UserFormChrome
{
	private ?\User $object = null;

	public function __construct(private readonly LegacyContext $ctx, private readonly UrlGenerator $urls)
	{
	}

	/**
	 * The \User given to the hooks and published as the global $object, as card.php l.105-120 builds it:
	 * empty on a creation, fetched with its extrafields for an existing user (l.119, l.1632-1635). On a
	 * redisplay it carries the posted extrafields, as setOptionalsFromPost() leaves it.
	 * Built once per request, so that what a doActions hook changes on it is seen by the form hooks.
	 */
	public function legacyObject(User $values): \User
	{
		if ($this->object === null) {
			$object = new \User($this->ctx->db);
			if ($values->id > 0 && $object->fetch($values->id, '', '', 1) > 0) {
				$object->fetch_optionals();
				$object->array_options = array_merge(is_array($object->array_options) ? $object->array_options : [], $values->extraFields);
			} else {
				$object->array_options = $values->extraFields;
			}
			$this->object = $object;
			$this->ctx->setPageObject($object);
		}

		return $this->object;
	}

	/**
	 * Rows of the form shown or not, with the conditions of card.php.
	 *
	 * $hierarchy: the supervisor and validator rows, always on a creation, with more than one user on an
	 * edit (l.2697). technicalId (l.2509) and member (l.3124) are rows of the edit form only, smallScreen
	 * its layout of the force password change row (l.2866).
	 *
	 * @return array{civility: bool, admin: bool, superadmin: bool, expenseValidator: bool, holidayValidator: bool, forcePasswordChange: bool, accountancyCode: bool, color: bool, categories: bool, language: bool, state: bool, warehouse: bool, emailRequired: bool, salary: bool, hierarchy: bool, technicalId: bool, member: bool, smallScreen: bool}
	 */
	public function visibility(bool $canSeeSalary, bool $canEditAdminFlag, bool $hierarchy = true): array
	{
		$me = $this->ctx->user;

		return [
			'hierarchy' => $hierarchy,
			'technicalId' => getDolGlobalString('MAIN_SHOW_TECHNICAL_ID') !== '',
			'member' => isModEnabled('member'),
			'smallScreen' => !empty($this->ctx->conf->dol_optimize_smallscreen),				// l.2866
			'civility' => getDolGlobalString('MAIN_USE_TITLE_FOR_USER') !== '',						// l.1081
			'admin' => $canEditAdminFlag,															// l.1171, !empty($user->admin)
			'superadmin' => $canEditAdminFlag && isModEnabled('multicompany') && $me !== null && empty($me->entity),	// l.1176
			'expenseValidator' => $hierarchy && isModEnabled('expensereport'),					// l.1235, l.2715
			'holidayValidator' => $hierarchy && isModEnabled('holiday'),							// l.1248, l.2735
			'forcePasswordChange' => $this->ctx->authMode() === 'dolibarr',						// l.1324
			'accountancyCode' => isModEnabled('accounting'),										// l.1462
			'color' => isModEnabled('agenda'),														// l.1470
			'categories' => isModEnabled('category') && $me !== null && $me->hasRight('categorie', 'read'),	// l.1478
			'language' => getDolGlobalInt('MAIN_MULTILANGS') > 0,									// l.1485
			'state' => !getDolGlobalString('USER_DISABLE_STATE'),									// l.1392
			'warehouse' => isModEnabled('stock') && getDolGlobalString('MAIN_DEFAULT_WAREHOUSE_USER') !== '',	// l.1541
			'emailRequired' => getDolGlobalString('USER_MAIL_REQUIRED') !== '',					// l.1438
			'salary' => $canSeeSalary,																// l.1553
		];
	}

	/**
	 * The legacy fragments of the form, by name. Only the fragments of the visible rows are built.
	 *
	 * @param User                $values   The values to show: the defaults or the stored ones, or what was posted on a redisplay
	 * @param string              $mode     'create' or 'edit'
	 * @param string|null         $password Creation: the password to show, null for a generated one (l.1287-1291)
	 * @param UserEditRights|null $edit     Edit: what the current user may change (required)
	 *
	 * @return array<string, string>
	 */
	public function fragments(User $values, string $mode, bool $canSeeSalary, bool $canEditAdminFlag, ?string $password = null, ?UserEditRights $edit = null): array
	{
		if ($mode === 'edit') {
			if ($edit === null) {
				throw new \InvalidArgumentException('The edit fragments need the UserEditRights');
			}

			return $this->editFragments($values, $canSeeSalary, $canEditAdminFlag, $edit);
		}
		$this->requireHelpers();

		$langs = $this->ctx->langs;
		$conf = $this->ctx->conf;
		$db = $this->ctx->db;
		$me = $this->ctx->user;
		$form = $this->ctx->form();
		$formcompany = new \FormCompany($db);
		$formadmin = new \FormAdmin($db);
		$object = $this->legacyObject($values);
		if ($values->extraFields !== []) {
			// The posted extrafields of a redisplay, as setOptionalsFromPost() leaves them on $object
			$object->array_options = $values->extraFields;
		}
		$show = $this->visibility($canSeeSalary, $canEditAdminFlag);
		$formName = $mode === 'create' ? 'createuser' : 'updateuser';
		$entity = (string) $conf->entity;
		$exclude = [$values->id > 0 ? $values->id : null];
		$f = [];

		// l.1077-1079
		$f['ficheHead'] = $this->capture(static fn () => dol_get_fiche_head([], '', '', 0, ''));
		$f['focusJs'] = $this->capture(static fn () => dol_set_focus('#lastname'));

		if ($show['civility']) {
			// l.1083
			$f['civility'] = $this->capture(static fn () => $formcompany->select_civility((string) $values->civility, 'civility_code'));
		}

		if ($show['admin']) {
			// l.1172-1174
			$f['adminLabel'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('Administrator'), $langs->trans('AdministratorDesc'), 1, 'help'));
			$f['admin'] = $this->capture(static fn () => $form->selectyesno('admin', $values->admin ? '1' : '0', 1, false, 0, 1));
			if ($show['superadmin']) {
				$f['admin'] .= $this->superadmin($values->entity === 0 && $values->admin);
			}
		}

		// l.1206-1208
		$arraygender = ['man' => $langs->trans('Genderman'), 'woman' => $langs->trans('Genderwoman'), 'other' => $langs->trans('Genderother')];
		$f['gender'] = $this->capture(static fn () => $form->selectarray('gender', $arraygender, (string) $values->gender, 1));

		// l.1220-1257
		$picto = img_picto('', 'user', 'class="pictofixedwidth"');
		$f['supervisor'] = $picto.$this->capture(static fn () => $form->select_dolusers($values->supervisorId ?? '', 'fk_user', 1, $exclude, 0, '', '', $entity, 0, 0, '', 0, '', 'maxwidth300 widthcentpercentminusx'));
		if ($show['expenseValidator']) {
			$f['expenseValidatorLabel'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('ForceUserExpenseValidator'), $langs->trans('ValidatorIsSupervisorByDefault'), 1, 'help'));
			$f['expenseValidator'] = $picto.$this->capture(static fn () => $form->select_dolusers($values->expenseValidatorId ?? '', 'fk_user_expense_validator', 1, $exclude, 0, '', '', $entity, 0, 0, '', 0, '', 'maxwidth300 widthcentpercentminusx'));
		}
		if ($show['holidayValidator']) {
			$f['holidayValidatorLabel'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('ForceUserHolidayValidator'), $langs->trans('ValidatorIsSupervisorByDefault'), 1, 'help'));
			$f['holidayValidator'] = $picto.$this->capture(static fn () => $form->select_dolusers($values->holidayValidatorId ?? '', 'fk_user_holiday_validator', 1, $exclude, 0, '', '', $entity, 0, 0, '', 0, '', 'maxwidth300 widthcentpercentminusx'));
		}

		// l.1262-1264
		$f['internal'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('Internal'), $langs->trans('InternalExternalDesc'), 1, 'help', '', 0, 2));

		// l.1270-1274
		$f['dateStartValidity'] = $this->capture(static fn () => $form->selectDate(self::timestamp($values->dateStartValidity), 'datestartvalidity', 0, 0, 1, 'formdatestartvalidity', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('from')));
		$f['dateEndValidity'] = $this->capture(static fn () => $form->selectDate(self::timestamp($values->dateEndValidity), 'dateendvalidity', 0, 0, 1, 'formdateendvalidity', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to')));

		$f['passwordField'] = $this->passwordField($object, $password ?? getRandomPassword(false));

		// l.1364-1390
		$f['addressLabel'] = $this->capture(static fn () => $form->editfieldkey('Address', 'address', '', $object, 0));
		$f['zipLabel'] = $this->capture(static fn () => $form->editfieldkey('Zip', 'zipcode', '', $object, 0));
		$f['zip'] = $this->capture(static fn () => $formcompany->select_ziptown((string) $values->zip, 'zipcode', ['town', 'selectcountry_id', 'state_id'], 6));
		$f['townLabel'] = $this->capture(static fn () => $form->editfieldkey('Town', 'town', '', $object, 0));
		$f['town'] = $this->capture(static fn () => $formcompany->select_ziptown((string) $values->town, 'town', ['zipcode', 'selectcountry_id', 'state_id']));
		$countryId = $values->countryId !== null && $values->countryId > 0 ? $values->countryId : '';
		$f['countryLabel'] = $this->capture(static fn () => $form->editfieldkey('Country', 'selectcountry_id', '', $object, 0));
		$f['country'] = img_picto('', 'country', 'class="pictofixedwidth"')
			.$this->capture(static fn () => $form->select_country($countryId, 'country_id'))
			.($me !== null && !empty($me->admin) ? info_admin($langs->trans('YouCanChangeValuesForThisListFromDictionarySetup'), 1) : '');
		if ($show['state']) {
			// l.1393-1396
			$f['stateLabel'] = $this->capture(static fn () => $form->editfieldkey('State', 'state_id', '', $object, 0));
			$f['state'] = img_picto('', 'state', 'class="pictofixedwidth"')
				.$this->capture(static fn () => $formcompany->select_state_ajax('country_id', $values->stateId ?? 0, $countryId === '' ? 0 : $countryId, 'state_id'));
		}

		// l.1400-1434
		$phoneCountry = $countryId === '' ? 0 : $countryId;
		$f['officePhone'] = $this->capture(static fn () => $form->showPhoneInput((string) $values->officePhone, 'office_phone', $phoneCountry, 'object_phoning', 'maxwidth200 widthcentpercentminusx'));
		$f['userMobile'] = $this->capture(static fn () => $form->showPhoneInput((string) $values->userMobile, 'user_mobile', $phoneCountry, 'object_phoning_mobile', 'maxwidth200 widthcentpercentminusx'));
		$f['officeFax'] = $this->capture(static fn () => $form->showPhoneInput((string) $values->officeFax, 'office_fax', $phoneCountry, 'object_phoning_fax', 'maxwidth200 widthcentpercentminusx'));
		// l.1440
		$f['emailPicto'] = img_picto('', 'object_email', 'class="pictofixedwidth"');

		if ($show['color']) {
			// l.1473
			$f['color'] = $this->capture(static fn () => \FormOther::selectColor((string) $values->color, 'color', null, 1, [], 'hideifnotset'));
		}
		if ($show['categories']) {
			// l.1479-1480: selectCategories() reads the posted usercats itself
			require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
			$f['categoriesLabel'] = $this->capture(static fn () => $form->editfieldkey('Categories', 'usercats', '', $object, 0));
			$f['categories'] = $this->capture(static fn () => $form->selectCategories(\Categorie::TYPE_USER, 'usercats', $object));
		}
		if ($show['language']) {
			// l.1486-1488
			$f['languageLabel'] = $this->capture(static fn () => $form->editfieldkey('DefaultLang', 'default_lang', '', $object, 0, 'string', '', 0, 0, 'id', $langs->trans('WarningNotLangOfInterface', $langs->transnoentitiesnoconv('UserGUISetup'))));
			$f['language'] = img_picto('', 'language', 'class="pictofixedwidth"')
				.$this->capture(static fn () => $formadmin->select_language((string) $values->lang, 'default_lang', 0, [], 1, 0, 0, 'maxwidth300 widthcentpercentminusx'));
		}

		// l.1502-1503
		$f['extrafields'] = $this->extrafields($object);

		// l.1506-1531
		$acceptlocallinktomedia = (acceptLocalLinktoMedia() > 0 ? 1 : 0);
		$signature = new \DolEditor('signature', (string) $values->signature, '', 138, 'dolibarr_notes', 'In', true, (bool) $acceptlocallinktomedia, !getDolGlobalString('FCKEDITOR_ENABLE_USERSIGN') ? 0 : 1, \ROWS_4, '90%');
		$f['signature'] = $this->capture(static fn () => $signature->Create(1));
		$notePublic = new \DolEditor('note_public', (string) $values->notePublic, '', 100, 'dolibarr_notes', '', false, true, getDolGlobalString('FCKEDITOR_ENABLE_NOTE_PUBLIC'), \ROWS_3, '90%');
		$f['notePublic'] = $this->capture(static fn () => $notePublic->Create());
		$notePrivate = new \DolEditor('note_private', (string) $values->notePrivate, '', 100, 'dolibarr_notes', '', false, true, getDolGlobalString('FCKEDITOR_ENABLE_NOTE_PRIVATE'), \ROWS_3, '90%');
		$f['notePrivate'] = $this->capture(static fn () => $notePrivate->Create());

		if ($show['warehouse']) {
			// l.1542-1543
			require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
			$formproduct = new \FormProduct($db);
			$f['warehouse'] = $this->capture(static fn () => $formproduct->selectWarehouses($values->warehouseId ?? '', 'fk_warehouse', 'warehouseopen', 1));
		}

		if ($show['salary']) {
			// l.1554-1581
			$langs->load('salaries');
			$f['thmLabel'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('THM'), $langs->trans('THMDescription'), 1, 'help', 'classthm'));
			$f['tjmLabel'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('TJM'), $langs->trans('TJMDescription'), 1, 'help', 'classtjm'));
			$f['salaryPicto'] = img_picto('', 'salary', 'class="pictofixedwidth paddingright"');
		}

		// l.1594-1606
		$f['dateEmployment'] = $this->capture(static fn () => $form->selectDate(self::timestamp($values->dateEmployment), 'dateemployment', 0, 0, 1, 'formdateemployment', 1, 1, 0, '', '', '', '', 1, '', $langs->trans('from')));
		$f['dateEmploymentEnd'] = $this->capture(static fn () => $form->selectDate(self::timestamp($values->dateEmploymentEnd), 'dateemploymentend', 0, 0, 1, 'formdateemploymentend', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to')));
		// l.1611
		$f['dateOfBirth'] = $this->capture(static fn () => $form->selectDate(self::timestamp($values->birth), 'dateofbirth', 0, 0, 1, $formName, 1, 0, 0, '', '', '', '', 1, '', '', 'tzserver'));

		// l.1617-1619
		$f['ficheEnd'] = $this->capture(static fn () => dol_get_fiche_end());
		$f['buttons'] = $this->capture(static fn () => $form->buttonsSaveCancel('CreateUser'));

		// l.3360-3362, printed after the form
		$usegenericrule = getDolGlobalString('USER_PASSWORD_GENERATED') == 'none' ? 1 : 0;
		$f['passwordJs'] = $this->capture(static fn () => dolJSToSetRandomPassword('password', 'generate_password', $usegenericrule));

		return $f;
	}

	/**
	 * The fragments of the edit form, card.php l.2498-3309 and l.3360-3362. Each one is the editable
	 * helper when the current user may edit ($permissiontoedit), else the read-only rendering card.php
	 * prints; the hidden inputs of the read-only fields are in the template.
	 *
	 * @return array<string, string>
	 */
	private function editFragments(User $values, bool $canSeeSalary, bool $canEditAdminFlag, UserEditRights $edit): array
	{
		$this->requireHelpers();
		$langs = $this->ctx->langs;
		$conf = $this->ctx->conf;
		$db = $this->ctx->db;
		$me = $this->ctx->user;
		$form = $this->ctx->form();
		$formcompany = new \FormCompany($db);
		$formadmin = new \FormAdmin($db);
		$formother = new \FormOther($db);
		$object = $this->legacyObject($values);
		if ($values->extraFields !== []) {
			$object->array_options = array_merge(is_array($object->array_options) ? $object->array_options : [], $values->extraFields);
		}
		$show = $this->visibility($canSeeSalary, $canEditAdminFlag, $edit->nbOfUsers > 1);
		$canEdit = $edit->canEdit;
		$ldap = $values->isLdapManaged;
		$domainUser = $ldap ? ' ('.$langs->trans('DomainUser').')' : '';
		$f = [];

		// l.1638-1643: rights of the edited user (read by the "force password change" row)
		if (!getDolGlobalString('MULTICOMPANY_TRANSVERSE_MODE')) {
			$object->loadRights();
			if (empty($object->nb_rights) && $object->status != 0 && empty($object->admin)) {
				setEventMessages($langs->trans('UserHasNoPermissions'), null, 'warnings');
			}
		}

		// l.1741 and l.2504: the tabs, the "user" one pointing to the new card
		$head = user_prepare_head($object);
		foreach ($head as $i => $tab) {
			if (($tab[2] ?? '') === 'user') {
				$head[$i][0] = $this->urls->generate('user_show', ['id' => $values->id]);
			}
		}
		$f['ficheHead'] = $this->capture(static fn () => dol_get_fiche_head($head, 'user', $langs->trans('User'), 0, 'user'));

		// l.2552-2611: internal or external, the third party and contact selectors for an admin on another user
		if ($edit->canLinkCompany) {
			$events = [['method' => 'getContacts', 'url' => dol_buildpath('/core/ajax/contacts.php?showempty=1&token='.currentToken(), 1), 'htmlname' => 'contactid', 'params' => ['add-customer-contact' => 'disabled']]];
			$socid = $values->companyId !== null && $values->companyId > 0 ? $values->companyId : 0;
			$contactId = $values->contactId !== null && $values->contactId > 0 ? $values->contactId : 0;
			$f['company'] = img_picto('', 'company', 'class="pictofixedwidth"')
				.$this->capture(static fn () => $form->select_company($socid, 'socid', '', '&nbsp;', 0, 0, $events, 0, 'widthcentpercentminusxx'.($socid > 0 || $contactId > 0 ? '  ' : ' ').'maxwidth300'))
				.'<span class="clearbothonsmartphone"></span>'
				.img_picto('', 'contact', 'class="pictofixedwidth"')
				.$this->capture(static fn () => $form->select_contact(0, $contactId, 'contactid', 1, '', '', 1, 'minwidth100imp widthcentpercentminusxx maxwidth300', true, 1))
				.($socid > 0 || $contactId > 0 ? $domainUser : '');
		} else {
			$type = $langs->trans($values->isExternal() ? 'External' : 'Internal');
			$f['externalType'] = $this->capture(static fn () => $form->textwithpicto($type, $langs->trans('InternalExternalDesc'))).$domainUser;
		}

		// l.2613-2694: the admin flag
		$f['adminLabel'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('Administrator'), $langs->trans('AdministratorDesc')));
		$f['admin'] = $this->adminEdit($object, $values, $canEditAdminFlag);

		if ($show['civility'] && $canEdit && !$ldap) {
			// l.2700
			$f['civility'] = $this->capture(static fn () => $formcompany->select_civility((string) $values->civility, 'civility_code'));
		}

		// l.2708-2716
		$arraygender = ['man' => $langs->trans('Genderman'), 'woman' => $langs->trans('Genderwoman'), 'other' => $langs->trans('Genderother')];
		$f['gender'] = $canEdit
			? $this->capture(static fn () => $form->selectarray('gender', $arraygender, (string) $values->gender, 1))
			: dol_escape_htmltag($arraygender[(string) $values->gender] ?? '');
		// l.2720
		$f['employeeLabel'] = $this->capture(static fn () => $form->editfieldkey('Employee', 'employee', '', $object, 0));

		if ($show['hierarchy']) {
			// l.2735-2789
			$exclude = [$values->id];
			$entity = (string) $values->entity;
			$picto = img_picto('', 'user', 'class="pictofixedwidth"');
			$select = fn (?int $selected, string $name): string => $canEdit
				? $picto.$this->capture(static fn () => $form->select_dolusers($selected ?? '', $name, 1, $exclude, 0, '', '', $entity, 0, 0, '', 0, '', 'widthcentpercentminusx maxwidth300'))
				: $this->userLink($selected ?? 0);
			$f['supervisor'] = $select($values->supervisorId, 'fk_user');
			if ($show['expenseValidator']) {
				$f['expenseValidatorLabel'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('ForceUserExpenseValidator'), $langs->trans('ValidatorIsSupervisorByDefault'), 1, 'help'));
				$f['expenseValidator'] = $select($values->expenseValidatorId, 'fk_user_expense_validator');
			}
			if ($show['holidayValidator']) {
				$f['holidayValidatorLabel'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('ForceUserHolidayValidator'), $langs->trans('ValidatorIsSupervisorByDefault'), 1, 'help'));
				$f['holidayValidator'] = $select($values->holidayValidatorId, 'fk_user_holiday_validator');
			}
		}

		// l.2803-2818
		$date = fn (?\DateTimeImmutable $value, string $name, string $formName, int $nowLink, string $label, string $tz = 'auto'): string => $canEdit
			? $this->capture(static fn () => $form->selectDate(self::timestamp($value), $name, 0, 0, 1, $formName, 1, $nowLink, 0, '', '', '', '', 1, '', $label, $tz))
			: dol_print_date(self::timestamp($value), 'day', $tz);
		$f['dateStartValidity'] = $date($values->dateStartValidity, 'datestartvalidity', 'formdatestartvalidity', 0, $langs->trans('from'));
		$f['dateEndValidity'] = $date($values->dateEndValidity, 'dateendvalidity', 'formdateendvalidity', 0, $langs->trans('to'));

		// l.2821-2873
		$f['forcePass'] = $this->forcePasswordEdit($object, $canEdit, $values->forcePasswordChange);
		$f['passwordField'] = $this->passwordFieldEdit($object, $edit, $show);

		// l.2908-2961
		$f['addressLabel'] = $this->capture(static fn () => $form->editfieldkey('Address', 'address', '', $object, 0));
		$f['zipLabel'] = $this->capture(static fn () => $form->editfieldkey('Zip', 'zipcode', '', $object, 0));
		$f['townLabel'] = $this->capture(static fn () => $form->editfieldkey('Town', 'town', '', $object, 0));
		$f['countryLabel'] = $this->capture(static fn () => $form->editfieldkey('Country', 'selectcountry_id', '', $object, 0));
		$countryId = $values->countryId ?? 0;
		if ($canEdit) {
			$f['zip'] = $this->capture(static fn () => $formcompany->select_ziptown((string) $values->zip, 'zipcode', ['town', 'selectcountry_id', 'state_id'], 6));
			$f['town'] = $this->capture(static fn () => $formcompany->select_ziptown((string) $values->town, 'town', ['zipcode', 'selectcountry_id', 'state_id']));
			$f['country'] = img_picto('', 'country', 'class="pictofixedwidth"')
				.$this->capture(static fn () => $form->select_country($countryId > 0 ? $countryId : '', 'country_id'))
				.($me !== null && !empty($me->admin) ? info_admin($langs->trans('YouCanChangeValuesForThisListFromDictionarySetup'), 1) : '');
		} else {
			$f['country'] = img_picto('', 'country', 'class="pictofixedwidth"').getCountry($countryId, '0');
		}
		if ($show['state']) {
			// l.2953-2961
			$f['stateLabel'] = $this->capture(static fn () => $form->editfieldkey('State', 'state_id', '', $object, 0));
			$f['state'] = $canEdit
				? img_picto('', 'state', 'class="pictofixedwidth"').$this->capture(static fn () => $formcompany->select_state_ajax('country_id', $values->stateId ?? 0, $countryId, 'state_id'))
				: dol_escape_htmltag((string) $object->state);
		}

		// l.2964-2997
		if ($canEdit && !$ldap) {
			$f['officePhone'] = $this->capture(static fn () => $form->showPhoneInput((string) $values->officePhone, 'office_phone', $countryId, 'phoning', 'maxwidth200 widthcentpercentminusx'));
			$f['userMobile'] = $this->capture(static fn () => $form->showPhoneInput((string) $values->userMobile, 'user_mobile', $countryId, 'phoning_mobile', 'maxwidth200 widthcentpercentminusx'));
			$f['officeFax'] = $this->capture(static fn () => $form->showPhoneInput((string) $values->officeFax, 'office_fax', $countryId, 'phoning_fax', 'maxwidth200 widthcentpercentminusx'));
		} else {
			$f['officePhonePicto'] = img_picto('', 'phoning', 'class="pictofixedwidth"');
			$f['userMobilePicto'] = img_picto('', 'phoning_mobile', 'class="pictofixedwidth"');
			$f['officeFaxPicto'] = img_picto('', 'phoning_fax', 'class="pictofixedwidth"');
		}
		// l.3003
		$f['emailPicto'] = img_picto('', 'object_email', 'class="pictofixedwidth"');

		if ($show['warehouse']) {
			// l.3041-3047; the "add a warehouse" link comes back to this form
			require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
			$formproduct = new \FormProduct($db);
			$back = $this->urls->generate('user_edit', ['id' => $values->id]);
			$f['warehouse'] = $this->capture(static fn () => $formproduct->selectWarehouses($values->warehouseId ?? '', 'fk_warehouse', 'warehouseopen', 1))
				.' <a href="'.DOL_URL_ROOT.'/product/stock/card.php?action=create&token='.newToken().'&backtopage='.urlencode($back).'"><span class="fa fa-plus-circle valignmiddle paddingleft" title="'.$langs->trans('AddWarehouse').'"></span></a>';
		}
		if ($show['color']) {
			// l.3065-3072
			$f['color'] = $canEdit
				? $this->capture(static fn () => \FormOther::selectColor((string) $values->color, 'color', null, 1, [], 'hideifnotset'))
				: $this->capture(static fn () => $formother->showColor((string) $values->color, ''));
		}
		if ($show['categories']) {
			// l.3085-3094
			require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
			$f['categoriesLabel'] = $this->capture(static fn () => $form->editfieldkey('Categories', 'usercats', '', $object, 0));
			$f['categories'] = $canEdit
				? $this->capture(static fn () => $form->selectCategories(\Categorie::TYPE_USER, 'usercats', $object))
				: $this->capture(static fn () => $form->showCategories($values->id, \Categorie::TYPE_USER, 1));
		}
		if ($show['language']) {
			// l.3097-3102
			$f['languageLabel'] = $this->capture(static fn () => $form->editfieldkey('DefaultLang', 'default_lang', '', $object, 0, 'string', '', 0, 0, 'id', $langs->trans('WarningNotLangOfInterface', $langs->transnoentitiesnoconv('UserGUISetup'))));
			$f['language'] = img_picto('', 'language', 'class="pictofixedwidth"')
				.$this->capture(static fn () => $formadmin->select_language((string) $values->lang, 'default_lang', 0, [], '1', 0, 0, 'widthcentpercentminusx maxwidth300'));
		}
		// l.3105-3108
		$f['status'] = $this->capture(static fn () => $object->getLibStatut(4));
		if ($show['member']) {
			// l.3131-3146
			$f['member'] = $this->member($object);
		}

		// l.3160-3170
		$f['extrafields'] = $this->extrafieldsEdit($object, $canEdit);

		// l.3173-3187
		if ($canEdit) {
			$acceptlocallinktomedia = (acceptLocalLinktoMedia() > 0 ? 1 : 0);
			$signature = new \DolEditor('signature', (string) $values->signature, '', 138, 'dolibarr_notes', 'In', false, (bool) $acceptlocallinktomedia, !getDolGlobalString('FCKEDITOR_ENABLE_USERSIGN') ? 0 : 1, \ROWS_4, '90%');
			$f['signature'] = $this->capture(static fn () => $signature->Create(1));
		} else {
			$f['signature'] = dol_htmlentitiesbr((string) $values->signature).(!empty($values->signature) ? dolGetSignatureQualityBadge($values->signature, $langs) : '');
		}

		if ($show['salary']) {
			// l.3222-3262
			$langs->load('salaries');
			$f['salaryPicto'] = img_picto('', 'salary', 'class="pictofixedwidth paddingright"');
			$f['thmLabel'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('THM'), $langs->trans('THMDescription'), 1, 'help', 'classthm'));
			$f['tjmLabel'] = $this->capture(static fn () => $form->textwithpicto($langs->trans('TJM'), $langs->trans('TJMDescription'), 1, 'help', 'classthm'));
			if (!$canEdit) {
				$f['thm'] = $values->thm !== null ? price($values->thm, 0, $langs, 1, -1, -1, $conf->currency) : '';
				$f['tjm'] = $values->tjm !== null ? price($values->tjm, 0, $langs, 1, -1, -1, $conf->currency) : '';
			}
		}

		// l.3265-3297
		$f['dateEmployment'] = $date($values->dateEmployment, 'dateemployment', 'formdateemployment', 1, $langs->trans('from'));
		$f['dateEmploymentEnd'] = $date($values->dateEmploymentEnd, 'dateemploymentend', 'formdateemploymentend', 0, $langs->trans('to'));
		$f['dateOfBirth'] = $date($values->birth, 'dateofbirth', 'updateuser', 0, '', 'tzserver');

		// l.3301
		$f['ficheEnd'] = $this->capture(static fn () => dol_get_fiche_end());

		// l.3360-3362, printed after the form
		$usegenericrule = getDolGlobalString('USER_PASSWORD_GENERATED') == 'none' ? 1 : 0;
		$f['passwordJs'] = $this->capture(static fn () => dolJSToSetRandomPassword('password', 'generate_password', $usegenericrule));

		return $f;
	}

	/**
	 * l.2613-2694: the admin cell of the edit form. A selector when the flag may be changed (an admin, not on
	 * himself, not the last superadmin with multicompany), else the hidden admin and superadmin and yes/no.
	 */
	private function adminEdit(\User $object, User $values, bool $canEditAdminFlag): string
	{
		$langs = $this->ctx->langs;
		$form = $this->ctx->form();
		$adminValue = $values->admin ? '1' : '0';
		if ($values->isExternal()) {
			// l.2615-2620
			$langs->load('admin');

			return '<input type="hidden" name="admin" value="'.$adminValue.'">'.yn($values->admin ? 1 : 0).' <span class="opacitymedium">('.$langs->trans('ExternalUser').')</span>';
		}

		$me = $this->ctx->user;
		$multicompany = isModEnabled('multicompany');
		$editable = $canEditAdminFlag && $me !== null;
		if ($editable) {
			// l.2622-2630: never downgrade the last superadmin
			$nbAdmin = $me->getNbOfUsers('active', '', 1);
			$nbSuperAdmin = $multicompany ? $me->getNbOfUsers('active', 'superadmin', 1) : 0;
			$editable = $multicompany
				? ($values->entity > 0 || (empty($me->entity) && $values->entity === 0) || $nbSuperAdmin > 1)
				: $nbAdmin >= 1;
		}
		if (!$editable) {
			// l.2681-2692
			$yn = yn($values->admin ? 1 : 0);
			$out = '<input type="hidden" name="admin" value="'.$adminValue.'">';
			$out .= '<input type="hidden" name="superadmin" value="'.($values->entity === 0 ? 1 : 0).'">';

			return $out.($multicompany && $values->entity === 0 ? $this->capture(static fn () => $form->textwithpicto($yn, $langs->trans('DontDowngradeSuperAdmin'), 1, 'warning')) : $yn);
		}

		$out = $this->capture(static fn () => $form->selectyesno('admin', $adminValue, 1, false, 0, 1));
		if ($multicompany && empty($me->entity)) {
			// l.2634-2678
			if (!empty($this->ctx->conf->use_javascript_ajax)) {
				$out .= '<script type="text/javascript">
									$(function() {
										var admin = $("select[name=admin]").val();
										if (admin == 0) {
											$("input[name=superadmin]")
													.prop("disabled", true)
													.prop("checked", false);
										}
										if ($("input[name=superadmin]").is(":checked")) {
											$("select[name=entity]")
													.prop("disabled", true);
										}
										$("select[name=admin]").change(function() {
											 if ( $(this).val() == 0 ) {
											 	$("input[name=superadmin]")
													.prop("disabled", true)
													.prop("checked", false);
											 	$("select[name=entity]")
													.prop("disabled", false);
											 } else {
											 	$("input[name=superadmin]")
													.prop("disabled", false);
											 }
										});
										$("input[name=superadmin]").change(function() {
											if ( $(this).is(":checked")) {
												$("select[name=entity]")
													.prop("disabled", true);
											} else {
												$("select[name=entity]")
													.prop("disabled", false);
											}
										});
									});
								</script>';
			}
			$checked = ($values->admin && $values->entity === 0) ? ' checked' : '';
			$out .= '<input type="checkbox" name="superadmin" id="superadmin" value="1"'.$checked.' /> <label for="superadmin">'.$langs->trans('SuperAdministrator').'</span>';
		}

		return $out;
	}

	/**
	 * l.2821-2836: the "force password change" checkbox, editable when the edited user may change his own
	 * password and the current user may edit.
	 */
	private function forcePasswordEdit(\User $object, bool $canEdit, bool $checked): string
	{
		$langs = $this->ctx->langs;
		$checkedAttr = $checked ? ' checked="checked"' : '';
		if ($object->hasRight('user', 'self', 'password')) {
			if ($canEdit) {
				return '<input type="checkbox" name="forcepasswordchange" id="forcepasswordchange" value="1"'.$checkedAttr.'>'
					.'<label class="opacitylow small" for="forcepasswordchange">'.$langs->trans('ForcePasswordChange').'</label>';
			}

			return '<input type="checkbox" name="forcepasswordchange" class="colorgrey" disabled value="1"'.$checkedAttr.'>'
				.'<span class="small">'.$langs->trans('ForcePasswordChange').'</small>';
		}

		return '<input type="checkbox" name="forcepasswordchange" value="1" class="colorgrey" disabled>'
			.'<span class="opacitymedium small" title="'.$langs->trans('UserDoesNotHaveRightsToChangeHisPassword').'">'.$langs->trans('ForcePasswordChange').'</span>';
	}

	/**
	 * l.2839-2867: the password cell of the edit form, a password input (always empty: the stored clear
	 * password is never loaded) for who may set it, and the printUserPasswordField hook.
	 *
	 * @param array<string, bool> $show
	 */
	private function passwordFieldEdit(\User $object, UserEditRights $edit, array $show): string
	{
		$langs = $this->ctx->langs;
		$form = $this->ctx->form();
		$auth = (string) ($this->ctx->conf->file->main_authentication ?? 'dolibarr');
		$or = ' '.$langs->trans('or').' ';
		$valuetoshow = '';
		if (preg_match('/ldap/', $auth)) {
			$valuetoshow .= ($valuetoshow ? $or : '').$langs->trans('PasswordOfUserInLDAP');
		}
		if (preg_match('/http/', $auth)) {
			// card.php passes its leftover $text: the label of the last validator row printed, else null
			$text = $show['holidayValidator'] ? $langs->trans('ForceUserHolidayValidator') : ($show['expenseValidator'] ? $langs->trans('ForceUserExpenseValidator') : '');
			$valuetoshow .= ($valuetoshow ? $or : '').$this->capture(static fn () => $form->textwithpicto($text, $langs->trans('DolibarrInHttpAuthenticationSoPasswordUseless', $auth), 1, 'warning'));
		}
		if ((preg_match('/dolibarr/', $auth) || preg_match('/forceuser/', $auth)) && $edit->canSetPassword) {
			$valuetoshow .= ($valuetoshow ? $or : '').'<input maxlength="128" type="password" class="minwidth300 maxwidth400 widthcentpercentminusx" id="password" name="password" value="" autocomplete="new-password" spellcheck="false">';
			if (!empty($this->ctx->conf->use_javascript_ajax)) {
				$none = getDolGlobalString('USER_PASSWORD_GENERATED') === 'none';
				$valuetoshow .= img_picto($none ? $langs->transnoentities('NoPasswordGenerationRuleConfigured') : $langs->transnoentities('Generate'), 'refresh', 'id="generate_password" class="paddingleft'.($none ? ' ' : ' linkobject').'"');
			}
		}

		$hookmanager = $this->ctx->hookmanager;
		$parameters = ['valuetoshow' => $valuetoshow, 'caneditpasswordandsee' => $edit->canSetPassword, 'caneditpasswordandsend' => $edit->canSendPassword];
		$action = 'edit';
		$reshook = 0;
		$printed = $this->capture(static function () use ($hookmanager, $parameters, $object, &$action, &$reshook): string {
			$reshook = (int) $hookmanager->executeHooks('printUserPasswordField', $parameters, $object, $action);

			return '';
		});
		$resPrint = (string) $hookmanager->resPrint;

		return $printed.($reshook > 0 ? $resPrint : $valuetoshow.$resPrint);
	}

	/**
	 * l.3160-3170: the formObjectOptions hook, then the extrafields in edit (or view) mode unless it replaces them.
	 */
	private function extrafieldsEdit(\User $object, bool $canEdit): string
	{
		$extrafields = new \ExtraFields($this->ctx->db);
		$extrafields->fetch_name_optionals_label($object->table_element);
		$hookmanager = $this->ctx->hookmanager;
		$parameters = ['colspan' => ' colspan="2"'];
		$action = 'edit';
		$reshook = 0;
		$printed = $this->capture(static function () use ($hookmanager, $parameters, $object, &$action, &$reshook): string {
			$reshook = (int) $hookmanager->executeHooks('formObjectOptions', $parameters, $object, $action);

			return '';
		});
		$out = $printed.(string) $hookmanager->resPrint;
		if (empty($reshook)) {
			$out .= $this->capture(static fn () => $object->showOptionals($extrafields, $canEdit ? 'edit' : 'view'));
		}

		return $out;
	}

	/**
	 * l.2745-2748: the link to a user of the hierarchy, as the read-only rows print it.
	 */
	private function userLink(int $id): string
	{
		$u = new \User($this->ctx->db);
		$u->fetch($id);

		return $this->capture(static fn () => $u->getNomUrl(-1));
	}

	/**
	 * l.3131-3146: the member linked to the user.
	 */
	private function member(\User $object): string
	{
		$langs = $this->ctx->langs;
		$langs->load('members');
		if (empty($object->fk_member)) {
			return '<span class="opacitymedium hideonsmartphone">'.$langs->trans('UserNotLinkedToMember').'</span>';
		}
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
		$adh = new \Adherent($this->ctx->db);
		$adh->fetch((int) $object->fk_member);
		$adh->ref = $adh->login;

		return $this->capture(static fn () => $adh->getNomUrl(1));
	}

	private function requireHelpers(): void
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formadmin.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/usergroups.lib.php';
		// Loaded by main.inc.php unless NOREQUIREAJAX: the selectors call ajax_combobox()
		require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
	}

	/**
	 * l.1559: the currency symbol shown after the THM, TJM and salary inputs.
	 */
	public function currencySymbol(): string
	{
		return $this->ctx->langs->getCurrencySymbol();
	}

	/**
	 * l.993: the title of the page above the form.
	 */
	public function title(string $title): string
	{
		return $this->capture(static fn () => load_fiche_titre($title, '', 'user'));
	}

	/**
	 * l.1128-1166: the script building the login from the lastname and the firstname (MAIN_BUILD_LOGIN_RULE),
	 * '' without javascript.
	 */
	public function loginJs(): string
	{
		if (empty($this->ctx->conf->use_javascript_ajax)) {
			return '';
		}
		$charforseparator = getDolGlobalString('MAIN_USER_SEPARATOR_CHAR_FOR_GENERATED_LOGIN', '.');
		if ($charforseparator == 'none') {
			$charforseparator = '';
		}
		$out = '<script>
			jQuery(document).ready(function() {
				$(".createloginauto").on("keyup", function() {
					console.log(".createloginauto change: We generate login when we have a lastname");

					lastname = $("#lastname").val().toLowerCase();
			';
		if (getDolGlobalString('MAIN_BUILD_LOGIN_RULE') == 'flastname') {
			$out .= '			firstname = $("#firstname").val().toLowerCase().replace(/\s+/g, \'\').trim()[0];';
			$charforseparator = '';
		} elseif (getDolGlobalString('MAIN_BUILD_LOGIN_RULE') == 'f.lastname') {
			$out .= '			firstname = $("#firstname").val().toLowerCase().replace(/\s+/g, \'\').trim()[0];';
		} else {
			$out .= '			firstname = $("#firstname").val().toLowerCase().replace(/\s+/g, \'\').trim();';
		}
		$out .= '
					login = "";
					if (lastname) {
						if (firstname) {
							login = firstname + \''.dol_escape_js($charforseparator).'\';
						}
						login += lastname.replace(/\s+/g, \'\').trim();
					}
					$("#login").val(login);
				})
			});
		</script>';

		return $out;
	}

	/**
	 * l.1176-1203: the superadmin checkbox and its script (multicompany, for a superadmin creator).
	 */
	private function superadmin(bool $checked): string
	{
		$out = '';
		if (!empty($this->ctx->conf->use_javascript_ajax)) {
			$out .= '<script type="text/javascript">
                            $(function() {
                                $("select[name=admin]").change(function() {
                                     if ( $(this).val() == 0 ) {
                                        $("input[name=superadmin]")
                                            .prop("disabled", true)
                                            .prop("checked", false);
                                        $("select[name=entity]")
                                            .prop("disabled", false);
                                     } else {
                                        $("input[name=superadmin]")
                                            .prop("disabled", false);
                                     }
                                });
                                $("input[name=superadmin]").change(function() {
                                    if ( $(this).is(":checked") ) {
                                        $("select[name=entity]")
                                            .prop("disabled", true);
                                    } else {
                                        $("select[name=entity]")
                                            .prop("disabled", false);
                                    }
                                });
                            });
                    </script>';
		}
		$out .= '<input type="checkbox" name="superadmin" id="superadmin" value="1"'.($checked ? ' checked' : '').($checked ? '' : ' disabled').' /> <label for="superadmin">'.$this->ctx->langs->trans('SuperAdministrator').'</span>';

		return $out;
	}

	/**
	 * l.1286-1321: the password input, its generate picto, and the printUserPasswordField hook
	 * (resPrint replaces when the hook returns > 0, is appended otherwise).
	 */
	private function passwordField(\User $object, string $password): string
	{
		$langs = $this->ctx->langs;
		$auth = (string) ($this->ctx->conf->file->main_authentication ?? 'dolibarr');
		$valuetoshow = '';
		if (preg_match('/ldap/', $auth)) {
			$valuetoshow .= ($valuetoshow ? ' + ' : '').$langs->trans('PasswordOfUserInLDAP').' (hidden)';
		}
		if (preg_match('/http/', $auth)) {
			$valuetoshow .= ($valuetoshow ? ' + ' : '').$langs->trans('HTTPBasicPassword');
		}
		if (preg_match('/dolibarr/', $auth) || preg_match('/forceuser/', $auth)) {
			// We do not use a field password but a field text to show new password to use.
			$valuetoshow .= ($valuetoshow ? ' + '.$langs->trans('DolibarrPassword') : '').'<input class="minwidth300 maxwidth400 widthcentpercentminusx" maxlength="128" type="text" id="password" name="password" value="'.dol_escape_htmltag($password).'" autocomplete="new-password" spellcheck="false">';
			if (!empty($this->ctx->conf->use_javascript_ajax)) {
				$valuetoshow .= img_picto($langs->transnoentities('Generate'), 'refresh', 'id="generate_password" class="linkobject paddingleft"');
			}
		}

		$hookmanager = $this->ctx->hookmanager;
		// On a creation, $id = 0: card.php l.164-169 leave both permissions false
		$parameters = ['valuetoshow' => $valuetoshow, 'password' => $password, 'caneditpasswordandsee' => false, 'caneditpasswordandsend' => false];
		$action = 'create';
		$reshook = 0;
		$printed = $this->capture(static function () use ($hookmanager, $parameters, $object, &$action, &$reshook): string {
			$reshook = (int) $hookmanager->executeHooks('printUserPasswordField', $parameters, $object, $action);

			return '';
		});
		$resPrint = (string) $hookmanager->resPrint;

		return $printed.($reshook > 0 ? $resPrint : $valuetoshow.$resPrint);
	}

	/**
	 * l.1502-1503: core/tpl/extrafields_add.tpl.php, included with the variables it reads.
	 */
	private function extrafields(\User $object): string
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$conf = $this->ctx->conf;
		$db = $this->ctx->db;
		$langs = $this->ctx->langs;
		$hookmanager = $this->ctx->hookmanager;
		$form = $this->ctx->form();
		$extrafields = new \ExtraFields($db);
		$extrafields->fetch_name_optionals_label($object->table_element);

		$action = 'create';
		$parameters = [];
		ob_start();
		try {
			include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_add.tpl.php';
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * What $fn prints, followed by what it returns (the helpers do one or the other).
	 *
	 * @param callable(): mixed $fn
	 */
	private function capture(callable $fn): string
	{
		ob_start();
		try {
			$result = $fn();
		} finally {
			$printed = (string) ob_get_clean();
		}

		return $printed.(is_string($result) ? $result : '');
	}

	/**
	 * The timestamp card.php gives selectDate(): dol_mktime(0, 0, 0, month, day, year) of the date, '' when none.
	 */
	private static function timestamp(?\DateTimeImmutable $date): int|string
	{
		if ($date === null) {
			return '';
		}
		$ts = dol_mktime(0, 0, 0, (int) $date->format('n'), (int) $date->format('j'), (int) $date->format('Y'));

		return is_int($ts) ? $ts : '';
	}
}
