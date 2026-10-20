<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\View;

use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Security\UserPermissionChecker;

/**
 * Legacy chrome and legacy-rendered fragments of the read-only user card
 * (htdocs/user/card.php, view mode, l.1700-2400). The only place of the module
 * calling these legacy helpers; they read their own globals.
 */
final class UserCardChrome
{
	public function __construct(private readonly LegacyContext $ctx, private readonly UrlGenerator $urls)
	{
	}

	public function build(\User $object, User $target, UserPermissionChecker $perms, bool $canSeeSalary, int $nbOfUsers): UserCardHtml
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/usergroups.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/emailsignature.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';

		$langs = $this->ctx->langs;
		$conf = $this->ctx->conf;
		$form = $this->ctx->form();
		$this->ctx->setPageObject($object);
		// card.php l.1638-1643: rights of the displayed user (read by the "password to change" row)
		if (!getDolGlobalString('MULTICOMPANY_TRANSVERSE_MODE')) {
			$object->loadRights();
			if (empty($object->nb_rights) && $object->status != 0 && empty($object->admin)) {
				setEventMessages($langs->trans('UserHasNoPermissions'), null, 'warnings');
			}
		}
		$formother = new \FormOther($this->ctx->db);
		$showHierarchy = $nbOfUsers > 1;

		// card.php l.1755-1771: login, admin picto
		$addadmin = '';
		if (isModEnabled('multicompany') && !empty($object->admin) && empty($object->entity)) {
			$addadmin .= img_picto($langs->trans('SuperAdministratorDesc'), 'superadmin', 'class="paddingleft valignmiddle"');
		} elseif (!empty($object->admin)) {
			$addadmin .= img_picto($langs->trans('AdministratorDesc'), 'admin', 'class="paddingleft valignmiddle"');
		}

		// card.php l.1808-1830: validators
		$expenseLabel = $expense = $holidayLabel = $holiday = null;
		if ($showHierarchy && isModEnabled('expensereport')) {
			$expenseLabel = $form->textwithpicto($langs->trans('ForceUserExpenseValidator'), $langs->trans('ValidatorIsSupervisorByDefault'), 1, 'help');
			$expense = $this->userLink((int) $object->fk_user_expense_validator, '');
		}
		if ($showHierarchy && isModEnabled('holiday')) {
			$holidayLabel = $form->textwithpicto($langs->trans('ForceUserHolidayValidator'), $langs->trans('ValidatorIsSupervisorByDefault'), 1, 'help');
			$holiday = $this->userLink((int) $object->fk_user_holiday_validator, '');
		}

		// card.php l.1875-1907: salary, THM, TJM
		$salary = $thmLabel = $thm = $tjmLabel = $tjm = null;
		if ($canSeeSalary) {
			$langs->load('salaries');
			$salary = ($object->salary != '' ? img_picto('', 'salary', 'class="pictofixedwidth paddingright"').'<span class="amount">'.price($object->salary, 0, $langs, 1, -1, -1, $conf->currency) : '').'</span>';
			$thmLabel = $form->textwithpicto($langs->trans('THM'), $langs->trans('THMDescription'), 1, 'help', 'classthm');
			$thm = $object->thm != '' ? '<span class="amount">'.price($object->thm, 0, $langs, 1, -1, -1, $conf->currency).'</span>' : '';
			$tjmLabel = $form->textwithpicto($langs->trans('TJM'), $langs->trans('TJMDescription'), 1, 'help', 'classtjm');
			$tjm = $object->tjm != '' ? '<span class="amount">'.price($object->tjm, 0, $langs, 1, -1, -1, $conf->currency).'</span>' : '';
		}

		// card.php l.1964-1983: default language
		$langLabel = $lang = null;
		if (getDolGlobalInt('MAIN_MULTILANGS')) {
			$langs->load('languages');
			$langLabel = $form->textwithpicto($langs->trans('DefaultLang'), $langs->trans('WarningNotLangOfInterface', $langs->transnoentitiesnoconv('UserGUISetup')));
			$lang = picto_from_langcode($object->lang, 'class="paddingrightonly saturatemedium opacitylow"').($object->lang ? $langs->trans('Language_'.$object->lang) : '');
		}

		// card.php l.2084-2105: force password change, only with the dolibarr authentication
		$pwdLabel = $pwd = null;
		if ($this->ctx->authMode() === 'dolibarr') {
			$pwdLabel = $form->textwithpicto($langs->trans('PasswordToChange'), $langs->trans('ForcePasswordChange'));
			if ($object->hasRight('user', 'self', 'password')) {
				$pwd = getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER') < 2
					? '<input type="checkbox" class="colorgrey" disabled name="forcepasswordchange" value="1"'.($object->force_pass_change ? ' checked="checked"' : '').'><span class="opacitymedium">'.yn($object->force_pass_change).'</span>'
					: yn($object->force_pass_change).'<span class="opacitymedium">'.yn($object->force_pass_change).'</span>';
			} else {
				$pwd = '<input type="checkbox" name="forcepasswordchange" value="1" disabled class="valignmiddle"><span class="opacitymedium" title="'.$langs->trans('UserDoesNotHaveRightsToChangeHisPassword').'">'.$langs->trans('No').'</span>';
			}
		}

		// card.php l.2244-2259: last login, unless hidden by MAIN_ENABLE_LOGINS_PRIVACY
		$lastLogin = null;
		$privacy = getDolGlobalInt('MAIN_ENABLE_LOGINS_PRIVACY');
		if ($privacy == 0 || ($privacy == 1 && $perms->isSelf($target))) {
			$lastLogin = '';
			if ($object->datepreviouslogin) {
				$lastLogin .= $form->textwithpicto(dol_print_date($object->datepreviouslogin, 'dayhour', 'tzuserrel'), $langs->trans('Previous'));
			}
			if ($object->datelastlogin) {
				if ($object->datepreviouslogin) {
					$lastLogin .= ' &nbsp; &nbsp; ';
				}
				$lastLogin .= $form->textwithpicto(dol_print_date($object->datelastlogin, 'dayhour', 'tzuserrel'), $langs->trans('Currently'));
			}
		}

		return new UserCardHtml(
			confirmHtml: $this->confirmClone($object, $form),
			tabsHtml: $this->tabs($object),
			bannerHtml: $this->banner($object, $perms->canList()),
			tabsEndHtml: dol_get_fiche_end(),
			loginHtml: showValueWithClipboardCPButton($object->login).$addadmin,
			typeLabelHtml: $form->textwithpicto($langs->trans('Type'), $langs->trans('InternalExternalDesc')),
			supervisorHtml: $showHierarchy ? $this->userLink((int) $object->fk_user, '<span class="opacitymedium">'.$langs->trans('None').'</span>') : null,
			expenseValidatorLabelHtml: $expenseLabel,
			expenseValidatorHtml: $expense,
			holidayValidatorLabelHtml: $holidayLabel,
			holidayValidatorHtml: $holiday,
			weeklyHours: (string) price2num($object->weeklyhours),
			salaryHtml: $salary,
			thmLabelHtml: $thmLabel,
			thmHtml: $thm,
			tjmLabelHtml: $tjmLabel,
			tjmHtml: $tjm,
			colorHtml: isModEnabled('agenda') ? $formother->showColor($object->color, '') : null,
			langLabelHtml: $langLabel,
			langHtml: $lang,
			companyHtml: isModEnabled('societe') ? $this->companyContact($object) : null,
			memberHtml: isModEnabled('member') ? $this->member($object) : null,
			signatureHtml: dol_htmlentitiesbr($object->signature).(!empty($object->signature) ? dolGetSignatureQualityBadge($object->signature, $langs) : ''),
			securityPictoHtml: img_picto('', 'security', 'class="paddingleft pictofixedwidth"'),
			passwordToChangeLabelHtml: $pwdLabel,
			passwordToChangeHtml: $pwd,
			lastLoginHtml: $lastLogin,
			actionsHtml: $this->actions($object, $target, $perms)
		);
	}

	/**
	 * card.php l.1741, the "user" tab pointing to the new route.
	 */
	private function tabs(\User $object): string
	{
		$head = user_prepare_head($object);
		foreach ($head as $i => $tab) {
			if (($tab[2] ?? '') === 'user') {
				$head[$i][0] = $this->urls->generate('user_show', ['id' => (int) $object->id]);
			}
		}

		return dol_get_fiche_head($head, 'user', $this->ctx->langs->trans('User'), -1, 'user', 0, '', '', 0, '', 1);
	}

	/**
	 * card.php l.1712-1750. dol_banner_tab() prints its output and needs the global $form.
	 */
	private function banner(\User $object, bool $canList): string
	{
		$langs = $this->ctx->langs;
		$linkback = $canList ? '<a href="'.DOL_URL_ROOT.'/user/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>' : '';

		$morehtmlref = '<a href="'.dolBuildUrl(DOL_URL_ROOT.'/user/vcard.php', ['id' => $object->id, 'output' => 'file', 'file' => dol_sanitizeFileName($object->getFullName($langs).'.vcf')]).'" class="refid valignmiddle" tabindex="-1" rel="noopener">';
		$morehtmlref .= img_picto($langs->trans('Download').' '.$langs->trans('VCard').' ('.$langs->trans('AddToContacts').')', 'vcard', 'class="valignmiddle marginleftonly paddingrightonly"');
		$morehtmlref .= '</a>';
		$urltovirtualcard = '/user/virtualcard.php?id='.((int) $object->id);
		$morehtmlref .= dolButtonToOpenUrlInDialogPopup('publicvirtualcard', $langs->transnoentitiesnoconv('PublicVirtualCardUrl').' - '.$object->getFullName($langs), img_picto($langs->trans('PublicVirtualCardUrl'), 'card', 'class="valignmiddle marginleftonly paddingrightonly"'), $urltovirtualcard, '', 'refid valignmiddle nohover');

		$this->ctx->form();
		ob_start();
		try {
			dol_banner_tab($object, 'id', $linkback, $canList ? 1 : 0, 'rowid', 'ref', $morehtmlref);
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * card.php l.1722-1738: clone confirmation, always output with javascript (opened by the Clone button).
	 */
	private function confirmClone(\User $object, \Form $form): string
	{
		$conf = $this->ctx->conf;
		if (empty($conf->use_javascript_ajax) || !empty($conf->dol_use_jmobile)) {
			return '';
		}
		$langs = $this->ctx->langs;
		$formquestionclone = [
			'text' => $langs->trans('ConfirmClone'),
			0 => ['type' => 'text', 'name' => 'clone_name', 'label' => $langs->trans('NewNameUserClone'), 'morecss' => 'width200'],
			1 => ['type' => 'checkbox', 'name' => 'clone_rights', 'label' => $langs->trans('CloneUserRights'), 'value' => 0],
			2 => ['type' => 'checkbox', 'name' => 'clone_categories', 'label' => $langs->trans('CloneCategoriesProduct'), 'value' => 0],
		];
		if (getDolGlobalString('USER_MAIL_REQUIRED')) {
			array_splice($formquestionclone, 2, 0, [['type' => 'text', 'name' => 'new_email', 'label' => $langs->trans('NewEmailUserClone'), 'morecss' => 'width200']]);
		}

		return $form->formconfirm(DOL_URL_ROOT.'/user/card.php?id='.$object->id, $langs->trans('ToClone'), $langs->trans('ConfirmUserClone', $object->firstname.' '.$object->lastname), 'confirm_clone', $formquestionclone, 'yes', 'action-clone', 350, 600);
	}

	/**
	 * card.php l.2006-2036.
	 */
	private function companyContact(\User $object): string
	{
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$s = '';
		if (!empty($object->socid) && $object->socid > 0) {
			$societe = new \Societe($this->ctx->db);
			$societe->fetch($object->socid);
			if ($societe->id > 0) {
				$s .= $societe->getNomUrl(1, '');
			}
		} else {
			$s .= '<span class="opacitymedium hideonsmartphone">'.$this->ctx->langs->trans('ThisUserIsNot').'</span>';
		}
		if (!empty($object->contact_id)) {
			$contact = new \Contact($this->ctx->db);
			$contact->fetch($object->contact_id);
			if ($contact->id > 0) {
				$s .= ($object->socid > 0 && $s) ? ' / ' : '<br>';
				$s .= $contact->getNomUrl(1, '');
			}
		}

		return $s;
	}

	/**
	 * card.php l.2038-2053.
	 */
	private function member(\User $object): string
	{
		$langs = $this->ctx->langs;
		$langs->load('members');
		if (!$object->fk_member) {
			return '<span class="opacitymedium hideonsmartphone">'.$langs->trans('UserNotLinkedToMember').'</span>';
		}
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
		$adh = new \Adherent($this->ctx->db);
		$adh->fetch($object->fk_member);
		$adh->ref = $adh->getFullname($langs);

		return $adh->getNomUrl(-1);
	}

	private function userLink(int $id, string $none): string
	{
		if ($id <= 0) {
			return $none;
		}
		$u = new \User($this->ctx->db);
		$u->fetch($id);

		return $u->getNomUrl(-1);
	}

	/**
	 * card.php l.2282-2400: action buttons (still pointing to card.php).
	 */
	private function actions(\User $object, User $target, UserPermissionChecker $perms): string
	{
		$langs = $this->ctx->langs;
		$conf = $this->ctx->conf;
		$card = DOL_URL_ROOT.'/user/card.php';
		$ldapManaged = !empty($object->ldap_sid);
		$out = '';

		$params = ['attr' => ['title' => '', 'class' => 'classfortooltip']];
		if ($perms->isInternal()) {
			$langs->load('mails');
			$canSendMail = !empty($object->email);
			if ($canSendMail) {
				unset($params['attr']['title']);
			} else {
				$params['attr']['title'] = $langs->trans('NoEMail');
			}
			$out .= dolGetButtonAction('', $langs->trans('SendMail'), 'email', dolBuildUrl($card, ['id' => $object->id, 'action' => 'presend', 'mode' => 'init']).'#formmailbeforetitle', '', $canSendMail, $params);
		}

		if ($perms->canModify($target)) {
			if (getDolGlobalString('MAIN_ONLY_LOGIN_ALLOWED')) {
				$params['attr']['title'] = $langs->trans('DisabledInMonoUserMode');
				$out .= dolGetButtonAction($langs->trans('Modify'), '', 'default', dolBuildUrl($card).'#', '', false, $params);
			} else {
				unset($params['attr']['title']);
				$out .= dolGetButtonAction($langs->trans('Modify'), '', 'default', dolBuildUrl($card, ['id' => $object->id, 'action' => 'edit'], true), '', true, $params);
			}
		} elseif ($perms->canModifyForPassword($target, $ldapManaged)) {
			unset($params['attr']['title']);
			$out .= dolGetButtonAction($langs->trans('Modify'), '', 'default', dolBuildUrl($card, ['id' => $object->id, 'action' => 'edit'], true), '', true, $params);
		}

		$params = ['attr' => ['title' => '', 'class' => 'classfortooltip']];
		if ($perms->canClone($target)) {
			$cloneButtonId = (!empty($conf->use_javascript_ajax) && empty($conf->dol_use_jmobile)) ? 'action-clone' : '';
			$out .= dolGetButtonAction($langs->trans('ToClone'), $langs->trans('ToClone'), 'clone', $card.'?id='.$object->id.'&action=clone&token='.newToken(), $cloneButtonId, $perms->canWriteUsers(), ['attr' => ['class' => 'reposition']]);
		}

		if (getDolGlobalString('USER_PASSWORD_GENERATED') != 'none') {
			if ($object->status == \User::STATUS_DISABLED) {
				$params['attr']['title'] = $langs->trans('UserDisabled');
				$out .= dolGetButtonAction($langs->trans('ReinitPassword'), '', 'default', dolBuildUrl($card).'#', '', false, $params);
			} elseif ($perms->canReinitPassword($target, $ldapManaged)) {
				unset($params['attr']['title']);
				$out .= dolGetButtonAction($langs->trans('ReinitPassword'), '', 'default', dolBuildUrl($card, ['id' => $object->id, 'action' => 'password'], true), '', true, $params);
			}

			if ($object->status == \User::STATUS_DISABLED) {
				$params['attr']['title'] = $langs->trans('UserDisabled');
				$out .= dolGetButtonAction($langs->trans('SendNewPassword'), '', 'default', $card.'#', '', false, $params);
			} elseif ($perms->canSendNewPassword($target, $ldapManaged)) {
				if ($object->email) {
					unset($params['attr']['title']);
					$out .= dolGetButtonAction($langs->trans('SendNewPassword'), '', 'default', dolBuildUrl($card, ['id' => $object->id, 'action' => 'passwordsend'], true), '', true, $params);
				} else {
					$params['attr']['title'] = $langs->trans('NoEMail');
					$out .= dolGetButtonAction($langs->trans('SendNewPassword'), '', 'default', $card.'#', '', false, $params);
				}
			}
		}

		if ($perms->canReactivate($target)) {
			unset($params['attr']['title']);
			$out .= dolGetButtonAction($langs->trans('Reactivate'), '', 'default', dolBuildUrl($card, ['id' => $object->id, 'action' => 'enable'], true), '', true, $params);
		}
		if ($perms->canDeactivate($target)) {
			unset($params['attr']['title']);
			$out .= dolGetButtonAction($langs->trans('DisableUser'), '', 'default', dolBuildUrl($card, ['id' => $object->id, 'action' => 'disable'], true), '', true, $params);
		} elseif ($perms->isSelf($target)) {
			$params['attr']['title'] = $langs->trans('CantDisableYourself');
			$out .= dolGetButtonAction($langs->trans('DisableUser'), '', 'default', $card.'#', '', false, $params);
		}
		if ($perms->canDelete($target)) {
			$enabled = $perms->canDeleteAdminTarget($target);
			$out .= dolGetButtonAction($langs->trans('DeleteUser'), '', 'default', dolBuildUrl($card, ['action' => 'delete', 'id' => $object->id], true), '', $enabled, ['attr' => ['class' => 'reposition']]);
		}

		return $out;
	}
}
