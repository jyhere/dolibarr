<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Service;

use Dolibarr\Core\Config;
use Dolibarr\Core\CurrentUser;

/**
 * The rows of the user card form shown or not, with the conditions of htdocs/user/card.php (create view
 * l.992-1626, edit view l.2498-3309). One source for both sides: UserFormChrome renders the rows from it and
 * UserInput only reads the fields of the rows shown (a hidden field keeps the stored value on an update, so a
 * setup that hides a row never blanks it).
 *
 * hierarchy: the supervisor and validator rows, always on a creation, with more than one user on an edit
 * (l.2697). technicalId (l.2509) and member (l.3124) are rows of the edit form only.
 */
final class UserFormFields
{
	public function __construct(
		public readonly bool $hierarchy,
		public readonly bool $technicalId,
		public readonly bool $member,
		public readonly bool $civility,
		public readonly bool $admin,
		public readonly bool $superadmin,
		public readonly bool $expenseValidator,
		public readonly bool $holidayValidator,
		public readonly bool $forcePasswordChange,
		public readonly bool $accountancyCode,
		public readonly bool $color,
		public readonly bool $categories,
		public readonly bool $language,
		public readonly bool $state,
		public readonly bool $warehouse,
		public readonly bool $emailRequired,
		public readonly bool $salary
	) {
	}

	/**
	 * @param string $authMode The authentication mode of the session ($_SESSION['dol_authmode'])
	 */
	public static function resolve(Config $config, CurrentUser $me, string $authMode, bool $canSeeSalary, bool $canEditAdminFlag, bool $hierarchy = true): self
	{
		return new self(
			hierarchy: $hierarchy,
			technicalId: $config->string('MAIN_SHOW_TECHNICAL_ID') !== '',
			member: $config->isModuleEnabled('member'),
			civility: $config->string('MAIN_USE_TITLE_FOR_USER') !== '',							// l.1081
			admin: $canEditAdminFlag,																// l.1171, !empty($user->admin)
			superadmin: $canEditAdminFlag && $config->isModuleEnabled('multicompany') && $me->entity() === 0,	// l.1176
			expenseValidator: $hierarchy && $config->isModuleEnabled('expensereport'),				// l.1235, l.2715
			holidayValidator: $hierarchy && $config->isModuleEnabled('holiday'),					// l.1248, l.2735
			forcePasswordChange: $authMode === 'dolibarr',											// l.1324
			accountancyCode: $config->isModuleEnabled('accounting'),								// l.1462
			color: $config->isModuleEnabled('agenda'),												// l.1470
			categories: $config->isModuleEnabled('category') && $me->hasRight('categorie', 'read'),	// l.1478
			language: $config->int('MAIN_MULTILANGS') > 0,											// l.1485
			// l.1392: !getDolGlobalString(), so '0' does not disable the state either
			state: in_array($config->string('USER_DISABLE_STATE'), ['', '0'], true),
			warehouse: $config->isModuleEnabled('stock') && $config->string('MAIN_DEFAULT_WAREHOUSE_USER') !== '',	// l.1541
			emailRequired: $config->string('USER_MAIL_REQUIRED') !== '',							// l.1438
			salary: $canSeeSalary																	// l.1553
		);
	}

	/**
	 * @return array{hierarchy: bool, technicalId: bool, member: bool, civility: bool, admin: bool, superadmin: bool, expenseValidator: bool, holidayValidator: bool, forcePasswordChange: bool, accountancyCode: bool, color: bool, categories: bool, language: bool, state: bool, warehouse: bool, emailRequired: bool, salary: bool}
	 */
	public function toArray(): array
	{
		return get_object_vars($this);
	}
}
