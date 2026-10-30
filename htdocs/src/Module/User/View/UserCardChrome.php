<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\View;

use Dolibarr\Core\Entity\EntityTabs;
use Dolibarr\Core\Entity\LegacyPageSettings;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Security\UserPermissionChecker;

/**
 * Legacy chrome and legacy-rendered fragments of the read-only user card
 * (htdocs/user/card.php, view mode, l.1700-2400). The only place of the module
 * calling these legacy helpers; they read their own globals. Also the tabs and banner of the generic
 * tabs of the core (EntityTabs), such as the notes of htdocs/user/note.php.
 */
final class UserCardChrome implements EntityTabs
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
			tabsHtml: $this->tabs($object, 'user'),
			bannerHtml: $this->banner($object, $perms->canList(), $this->cardMoreHtmlRef($object), 'user_show'),
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
	 * card.php l.2416-2491: the groups of the user, the form adding one and the removals. The form posts
	 * to the user_groups route; each removal, a GET link in card.php, is a small POST form here (outside
	 * the add form, which cannot contain forms) submitted by a button styled as the link.
	 *
	 * @param string $action The action of card.php, passed to the formAddUserToGroup hook
	 */
	public function groupsBlock(\User $object, bool $canEditGroups, bool $canReadGroups, LegacyHooks $hooks, string $action = ''): string
	{
		if (!$canReadGroups) {
			return '';
		}
		require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
		$langs = $this->ctx->langs;
		$form = $this->ctx->form();
		$url = $this->urls->generate('user_groups', ['id' => (int) $object->id]);

		$out = '<!-- Group section -->'."\n";
		$out .= load_fiche_titre($langs->trans('ListOfGroupsForUser'), '', '');

		$exclude = [];
		$usergroup = new \UserGroup($this->ctx->db);
		$groupslist = $usergroup->listGroupsForUser($object->id, false);
		if (!empty($groupslist)) {
			foreach ($groupslist as $groupforuser) {
				$exclude[] = $groupforuser->id;
			}
		}

		$hookObject = $object;
		$hook = $hooks->execute('formAddUserToGroup', ['caneditgroup' => $canEditGroups, 'groupslist' => $groupslist, 'exclude' => $exclude], $hookObject, $action);
		$out .= $hook->output.$hook->print;
		if ($hook->code !== 0) {
			return $out;
		}

		$removeForms = '';
		if ($canEditGroups) {
			$out .= '<form action="'.$url.'" method="POST">'."\n";
			$out .= '<input type="hidden" name="token" value="'.newToken().'" />';
			$out .= '<input type="hidden" name="action" value="addgroup" />';
			$out .= '<input type="hidden" name="page_y" value="" />';
		}
		$out .= '<!-- List of groups of the user -->'."\n";
		$out .= '<table class="noborder centpercent">'."\n";
		$out .= '<tr class="liste_titre">';
		$out .= '<th class="liste_titre right" colspan="2">';
		if ($canEditGroups) {
			$out .= $form->select_dolgroups(0, 'group', 1, $exclude, 0, '', [], (string) $object->entity, false, 'maxwidth150');
			$out .= ' &nbsp; ';
			$out .= '<input type="hidden" name="entity" value="'.$this->ctx->conf->entity.'" />';
			$out .= '<input type="submit" class="button buttongen button-add reposition" value="'.$langs->trans('Add').'" />';
		}
		$out .= '</th></tr>'."\n";

		if (!empty($groupslist)) {
			foreach ($groupslist as $group) {
				$out .= '<tr class="oddeven">';
				$out .= '<td class="tdoverflowmax200">';
				$out .= $canEditGroups ? $group->getNomUrl(1) : img_object($langs->trans('ShowGroup'), 'group').' '.$group->name;
				$out .= '</td>';
				$out .= '<td class="right">';
				if ($canEditGroups) {
					$formId = 'removegroup'.((int) $group->id);
					$out .= '<button type="submit" form="'.$formId.'" class="reposition nobordertransp nopadding cursorpointer">';
					$out .= img_picto($langs->trans('RemoveFromGroup'), 'unlink');
					$out .= '</button>';
					$removeForms .= '<form id="'.$formId.'" action="'.$url.'" method="POST">';
					$removeForms .= '<input type="hidden" name="token" value="'.newToken().'" />';
					$removeForms .= '<input type="hidden" name="action" value="removegroup" />';
					$removeForms .= '<input type="hidden" name="group" value="'.((int) $group->id).'" />';
					$removeForms .= '<input type="hidden" name="page_y" value="" />';
					$removeForms .= '</form>'."\n";
				} else {
					$out .= '&nbsp;';
				}
				$out .= "</td></tr>\n";
			}
		} else {
			$out .= '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
		}

		$out .= '</table>';
		if ($canEditGroups) {
			$out .= '</form>';
		}
		$out .= $removeForms;

		return $out.'<br>';
	}

	/**
	 * card.php l.3315-3355: generated documents (left) and latest events (right). The documents form keeps
	 * card.php as its source page: generating and removing a document stay legacy actions (card.php l.943).
	 *
	 * @param bool $genAllowed card.php l.3321
	 * @param bool $delAllowed card.php l.3322
	 * @param int  $socid      The company of the current user (card.php l.124-127)
	 */
	public function bottomBlocks(\User $object, bool $genAllowed, bool $delAllowed, int $socid): string
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formactions.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$langs = $this->ctx->langs;
		$conf = $this->ctx->conf;

		ob_start();
		try {
			print '<div class="fichecenter"><div class="fichehalfleft">';

			$filename = dol_sanitizeFileName($object->ref);
			$filedir = $conf->user->dir_output.'/'.dol_sanitizeFileName($object->ref);
			$urlsource = DOL_URL_ROOT.'/user/card.php?id='.$object->id;
			$societe = null;
			if ($object->socid) {
				$societe = new \Societe($this->ctx->db);
				$societe->fetch($object->socid);
			}
			$formfile = new \FormFile($this->ctx->db);
			print $formfile->showdocuments('user', $filename, $filedir, $urlsource, $genAllowed ? 1 : 0, $delAllowed ? 1 : 0, $object->model_pdf, 0, 0, 0, 28, 0, '', '', '', $societe === null || empty($societe->default_lang) ? '' : $societe->default_lang);

			$MAXEVENT = 10;
			$morehtmlcenter = '<div class="nowraponall">';
			$morehtmlcenter .= dolGetButtonTitle($langs->trans('FullConversation'), '', 'fa fa-comments imgforviewmode', DOL_URL_ROOT.'/user/messaging.php?id='.$object->id);
			$morehtmlcenter .= dolGetButtonTitle($langs->trans('SeeAll'), '', 'fa fa-bars imgforviewmode', DOL_URL_ROOT.'/user/agenda.php?id='.$object->id);
			$morehtmlcenter .= '</div>';

			print '</div><div class="fichehalfright">';

			$formactions = new \FormActions($this->ctx->db);
			$formactions->showactions($object, 'user', $socid, 1, 'listactions', $MAXEVENT, '', $morehtmlcenter, $object->id);

			print '</div></div>';
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * The chrome of a tab page of the user as htdocs/user/<tab>.php prints it: the note tab
	 * (user/note.php l.103-155) and the rights tab (user/perms.php l.344-472).
	 *
	 * @return array{tabsHtml: string, bannerHtml: string, tabsEndHtml: string, headerRowsHtml: string}
	 *
	 * @throws \InvalidArgumentException When $legacyObject is not a legacy user, or the tab is not supported
	 */
	public function chrome(object $legacyObject, object $entity, string $activeTab): array
	{
		if (!$legacyObject instanceof \User) {
			throw new \InvalidArgumentException('UserCardChrome only handles \\User, got '.$legacyObject::class);
		}
		if ($activeTab !== 'note' && $activeTab !== 'rights' && $activeTab !== 'document') {
			throw new \InvalidArgumentException('No chrome for the user tab '.$activeTab);
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/usergroups.lib.php';
		$langs = $this->ctx->langs;
		$this->ctx->setPageObject($legacyObject);
		// note.php l.58, perms.php l.98
		$legacyObject->loadRights();

		// note.php l.112-120 and perms.php l.414-427: the back link and the navigation, from the legacy user's rights
		$me = $this->ctx->user;
		$canList = $me !== null && ($me->hasRight('user', 'user', 'read') || $me->admin);
		$morehtmlref = '<a href="'.DOL_URL_ROOT.'/user/vcard.php?id='.$legacyObject->id.'&output=file&file='.urlencode(dol_sanitizeFileName($legacyObject->getFullName($langs).'.vcf')).'" class="refid valignmiddle" tabindex="-1" rel="noopener">';
		$morehtmlref .= img_picto($langs->trans('Download').' '.$langs->trans('VCard'), 'vcard', 'class="valignmiddle marginleftonly paddingrightonly"');
		$morehtmlref .= '</a>';
		// perms.php l.425 gives the virtual card picto the class refid
		$morehtmlref .= $this->virtualCardButton($legacyObject, $activeTab === 'rights' ? 'refid valignmiddle marginleftonly paddingrightonly' : 'valignmiddle marginleftonly paddingrightonly');

		return [
			'tabsHtml' => $this->tabs($legacyObject, $activeTab),
			'bannerHtml' => $this->banner($legacyObject, $canList, $morehtmlref, $this->bannerRoute($activeTab), $activeTab === 'rights' ? [] : ['entity' => 'user']),
			'tabsEndHtml' => dol_get_fiche_end(),
			'headerRowsHtml' => $activeTab === 'rights' ? $this->rightsHeaderRows($legacyObject) : $this->noteLoginRow($legacyObject),
		];
	}

	/**
	 * htdocs/user/note.php: $langs->loadLangs() l.52, initHooks() l.77, the doActions parameters l.83 with
	 * $socid the company of the current user (l.71-74). htdocs/user/perms.php: l.52, l.89 and l.107 (socid l.78-81).
	 *
	 * @throws \InvalidArgumentException When the page is not supported
	 */
	public function legacyPageSettings(string $page): LegacyPageSettings
	{
		if ($page !== 'note' && $page !== 'rights' && $page !== 'document') {
			throw new \InvalidArgumentException('No legacy page settings for the user page '.$page);
		}
		$socid = 0;
		$me = $this->ctx->user;
		if ($me !== null && $me->socid > 0) {
			$socid = (int) $me->socid;
		}

		if ($page === 'document') {
			// document.php l.46, l.87, l.130
			return new LegacyPageSettings(['users', 'other'], ['usercard', 'userdoc', 'globalcard'], ['id' => $socid]);
		}

		return $page === 'rights'
			? new LegacyPageSettings(['users', 'admin'], ['usercard', 'userperms', 'globalcard'], ['socid' => $socid])
			: new LegacyPageSettings(['companies', 'members', 'bills', 'users'], ['usercard', 'usernote', 'globalcard'], ['id' => $socid]);
	}

	/**
	 * The tabs of user_prepare_head() with $active selected; the "user" tab (card.php l.1741), the "note"
	 * tab (when the notes component is registered) and the "rights" tab point to the new routes. The note badge is computed by user_prepare_head() from the legacy object.
	 */
	private function tabs(\User $object, string $active): string
	{
		$head = user_prepare_head($object);
		foreach ($head as $i => $tab) {
			if (($tab[2] ?? '') === 'user') {
				$head[$i][0] = $this->urls->generate('user_show', ['id' => (int) $object->id]);
			} elseif (($tab[2] ?? '') === 'note' && $this->urls->has('entity_note')) {
				$head[$i][0] = $this->urls->generate('entity_note', ['entity' => 'user', 'id' => (int) $object->id]);
			} elseif (($tab[2] ?? '') === 'document' && $this->urls->has('entity_documents')) {
				$head[$i][0] = $this->urls->generate('entity_documents', ['entity' => 'user', 'id' => (int) $object->id]);
			} elseif (($tab[2] ?? '') === 'rights' && $this->urls->has('user_rights')) {
				$head[$i][0] = $this->urls->generate('user_rights', ['id' => (int) $object->id]);
			}
		}

		// note.php l.106 and perms.php l.347 pass the defaults after the picto; card.php l.1741 and document.php l.162 enable the drag and drop of files
		return $active === 'user' || $active === 'document'
			? dol_get_fiche_head($head, $active, $this->ctx->langs->trans('User'), -1, 'user', 0, '', '', 0, '', 1)
			: dol_get_fiche_head($head, $active, $this->ctx->langs->trans('User'), -1, 'user');
	}

	private function bannerRoute(string $activeTab): string
	{
		return match ($activeTab) {
			'rights' => 'user_rights',
			'document' => 'entity_documents',
			default => 'entity_note',
		};
	}

	/**
	 * card.php l.1745-1748: the vcard and virtual card links of the banner.
	 */
	private function cardMoreHtmlRef(\User $object): string
	{
		$langs = $this->ctx->langs;
		$morehtmlref = '<a href="'.dolBuildUrl(DOL_URL_ROOT.'/user/vcard.php', ['id' => $object->id, 'output' => 'file', 'file' => dol_sanitizeFileName($object->getFullName($langs).'.vcf')]).'" class="refid valignmiddle" tabindex="-1" rel="noopener">';
		$morehtmlref .= img_picto($langs->trans('Download').' '.$langs->trans('VCard').' ('.$langs->trans('AddToContacts').')', 'vcard', 'class="valignmiddle marginleftonly paddingrightonly"');
		$morehtmlref .= '</a>';

		return $morehtmlref.$this->virtualCardButton($object);
	}

	private function virtualCardButton(\User $object, string $pictoClass = 'valignmiddle marginleftonly paddingrightonly'): string
	{
		$langs = $this->ctx->langs;
		$urltovirtualcard = '/user/virtualcard.php?id='.((int) $object->id);

		return dolButtonToOpenUrlInDialogPopup('publicvirtualcard', $langs->transnoentitiesnoconv('PublicVirtualCardUrl').' - '.$object->getFullName($langs), img_picto($langs->trans('PublicVirtualCardUrl'), 'card', 'class="'.$pictoClass.'"'), $urltovirtualcard, '', 'refid valignmiddle nohover');
	}

	/**
	 * card.php l.1712-1750 and note.php l.112-131. dol_banner_tab() prints its output and needs the global $form.
	 *
	 * @param array<string,string> $fixed Route variables of $route other than id
	 */
	private function banner(\User $object, bool $canList, string $morehtmlref, string $route, array $fixed = []): string
	{
		$linkback = $canList ? '<a href="'.$this->urls->generate('user_list').'?restore_lastsearch_values=1">'.$this->ctx->langs->trans('BackToList').'</a>' : '';

		$this->ctx->form();
		ob_start();
		try {
			dol_banner_tab($object, 'id', $linkback, $canList ? 1 : 0, 'rowid', 'ref', $morehtmlref);
		} finally {
			$html = (string) ob_get_clean();
		}

		// A tab whose route does not exist yet keeps the legacy links of the banner
		return $this->urls->has($route) ? $this->navigationLinks($html, $route, $fixed) : $html;
	}

	/**
	 * Form::showrefnav() builds its previous/next links as $_SERVER['PHP_SELF'].'?id=N', and PHP_SELF is the route
	 * path of the current tab: they are rewritten to the same tab of the other user (other query parameters kept).
	 *
	 * @param array<string,string> $fixed Route variables other than id
	 */
	private function navigationLinks(string $html, string $route, array $fixed): string
	{
		$self = (string) ($_SERVER['PHP_SELF'] ?? '');
		if ($self === '') {
			return $html;
		}

		return (string) preg_replace_callback(
			'#href="'.preg_quote($self, '#').'\?id=(\d+)((?:&|&amp;)[^"]*)?"#',
			function (array $matches) use ($route, $fixed): string {
				$query = isset($matches[2]) ? ltrim(preg_replace('#^(&amp;|&)#', '', $matches[2]) ?? '', '&') : '';

				return 'href="'.$this->urls->generate($route, $fixed + ['id' => (int) $matches[1]]).($query !== '' ? '?'.$query : '').'"';
			},
			$html
		);
	}

	/**
	 * perms.php l.435-469: the Login row (anchored) and the Type row above the rights.
	 */
	private function rightsHeaderRows(\User $object): string
	{
		$langs = $this->ctx->langs;
		$out = $this->noteLoginRow($object, ' id="anchorforperms"')."\n";
		$out .= '<tr><td>'.$this->ctx->form()->textwithpicto($langs->trans('Type'), $langs->trans('InternalExternalDesc')).'</td><td>';
		$out .= '<span class="badgeneutral">'.$langs->trans($object->socid > 0 ? 'External' : 'Internal');
		if ($object->ldap_sid) {
			$out .= ' ('.$langs->trans('DomainUser').')';
		}

		return $out.'</span></td></tr>'."\n";
	}

	/**
	 * note.php l.141-158: the Login row above the notes (perms.php l.436-452 with the anchor attribute).
	 */
	private function noteLoginRow(\User $object, string $anchor = ''): string
	{
		$langs = $this->ctx->langs;
		$out = '<tr><td'.$anchor.' class="titlefield">'.$langs->trans('Login').'</td>';
		if (!empty($object->ldap_sid) && $object->status == 0) {
			$out .= '<td class="error">'.$langs->trans('LoginAccountDisableInDolibarr').'</td>';
		} else {
			$addadmin = '';
			if (isModEnabled('multicompany') && !empty($object->admin) && empty($object->entity)) {
				$addadmin .= img_picto($langs->trans('SuperAdministratorDesc'), 'superadmin', 'class="paddingleft valignmiddle"');
			} elseif (!empty($object->admin)) {
				$addadmin .= img_picto($langs->trans('AdministratorDesc'), 'admin', 'class="paddingleft valignmiddle"');
			}
			$out .= '<td>'.showValueWithClipboardCPButton($object->login).$addadmin.'</td>';
		}

		return $out.'</tr>';
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
		$html = '';
		if (!empty($object->socid) && $object->socid > 0) {
			$societe = new \Societe($this->ctx->db);
			$societe->fetch($object->socid);
			if ($societe->id > 0) {
				$html .= $societe->getNomUrl(1, '');
			}
		} else {
			$html .= '<span class="opacitymedium hideonsmartphone">'.$this->ctx->langs->trans('ThisUserIsNot').'</span>';
		}
		if (!empty($object->contact_id)) {
			$contact = new \Contact($this->ctx->db);
			$contact->fetch($object->contact_id);
			if ($contact->id > 0) {
				$html .= ($object->socid > 0 && $html) ? ' / ' : '<br>';
				$html .= $contact->getNomUrl(1, '');
			}
		}

		return $html;
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
		$linked = new \User($this->ctx->db);
		$linked->fetch($id);

		return $linked->getNomUrl(-1);
	}

	/**
	 * card.php l.2282-2400: action buttons. Modify, the password, status and delete buttons point to the new
	 * routes (their dialogs on the card); SendMail and ToClone still to card.php.
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
				$out .= dolGetButtonAction($langs->trans('Modify'), '', 'default', $this->urls->generate('user_edit', ['id' => (int) $object->id]), '', true, $params);
			}
		} elseif ($perms->canModifyForPassword($target, $ldapManaged)) {
			unset($params['attr']['title']);
			$out .= dolGetButtonAction($langs->trans('Modify'), '', 'default', $this->urls->generate('user_edit', ['id' => (int) $object->id]), '', true, $params);
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
				$out .= dolGetButtonAction($langs->trans('ReinitPassword'), '', 'default', $this->urls->generate('user_password', ['id' => (int) $object->id]), '', true, $params);
			}

			if ($object->status == \User::STATUS_DISABLED) {
				$params['attr']['title'] = $langs->trans('UserDisabled');
				$out .= dolGetButtonAction($langs->trans('SendNewPassword'), '', 'default', $card.'#', '', false, $params);
			} elseif ($perms->canSendNewPassword($target, $ldapManaged)) {
				if ($object->email) {
					unset($params['attr']['title']);
					$out .= dolGetButtonAction($langs->trans('SendNewPassword'), '', 'default', $this->urls->generate('user_password', ['id' => (int) $object->id, 'mode' => 'send']), '', true, $params);
				} else {
					$params['attr']['title'] = $langs->trans('NoEMail');
					$out .= dolGetButtonAction($langs->trans('SendNewPassword'), '', 'default', $card.'#', '', false, $params);
				}
			}
		}

		if ($perms->canReactivate($target)) {
			unset($params['attr']['title']);
			$out .= dolGetButtonAction($langs->trans('Reactivate'), '', 'default', $this->urls->generate('user_show', ['id' => (int) $object->id, 'confirm' => 'enable']), '', true, $params);
		}
		if ($perms->canDeactivate($target)) {
			unset($params['attr']['title']);
			$out .= dolGetButtonAction($langs->trans('DisableUser'), '', 'default', $this->urls->generate('user_show', ['id' => (int) $object->id, 'confirm' => 'disable']), '', true, $params);
		} elseif ($perms->isSelf($target)) {
			$params['attr']['title'] = $langs->trans('CantDisableYourself');
			$out .= dolGetButtonAction($langs->trans('DisableUser'), '', 'default', $card.'#', '', false, $params);
		}
		if ($perms->canSeeDeleteButton($target)) {
			$enabled = $perms->canDeleteAdminTarget($target);
			$out .= dolGetButtonAction($langs->trans('DeleteUser'), '', 'default', $this->urls->generate('user_show', ['id' => (int) $object->id, 'confirm' => 'delete']), '', $enabled, ['attr' => ['class' => 'reposition']]);
		}

		return $out;
	}
}
