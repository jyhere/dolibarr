<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

/**
 * Holds the legacy Dolibarr globals. The only place allowed to read $GLOBALS.
 */
final class LegacyContext
{
	public function __construct(
		public readonly \Conf $conf,
		public readonly \DoliDB $db,
		public readonly \Translate $langs,
		public readonly ?\User $user,
		public readonly \HookManager $hookmanager,
	) {
	}

	/**
	 * @throws \LogicException If a required global is missing
	 */
	public static function fromGlobals(): self
	{
		$conf = $GLOBALS['conf'] ?? null;
		$db = $GLOBALS['db'] ?? null;
		$langs = $GLOBALS['langs'] ?? null;
		$user = $GLOBALS['user'] ?? null;
		$hookmanager = $GLOBALS['hookmanager'] ?? null;

		if (!$conf instanceof \Conf || !$db instanceof \DoliDB || !$langs instanceof \Translate || !$hookmanager instanceof \HookManager) {
			throw new \LogicException('Dolibarr globals conf, db, langs and hookmanager must be initialized');
		}

		return new self($conf, $db, $langs, $user instanceof \User ? $user : null, $hookmanager);
	}

	/**
	 * $dolibarr_main_db_readonly of conf.php, read at each call as DoliDB::query() does: the native database
	 * drivers refuse writes while it is set.
	 */
	public static function databaseReadOnly(): bool
	{
		return !empty($GLOBALS['dolibarr_main_db_readonly']);
	}

	/**
	 * The global $form that legacy helpers such as dol_banner_tab() expect.
	 * It is created as top_menu() does when the page has not printed its header yet.
	 */
	public function form(): \Form
	{
		$form = $GLOBALS['form'] ?? null;
		if (!$form instanceof \Form) {
			require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
			$form = new \Form($this->db);
			$GLOBALS['form'] = $form;
		}

		return $form;
	}

	/**
	 * The global $mysoc (the company of the instance) set by master.inc.php, null when not loaded.
	 */
	public function mysoc(): ?\Societe
	{
		$mysoc = $GLOBALS['mysoc'] ?? null;

		return $mysoc instanceof \Societe ? $mysoc : null;
	}

	/**
	 * The global $mc of the multicompany module (its getInfo() gives the label of an entity), null without it.
	 */
	public function multicompany(): ?object
	{
		$mc = $GLOBALS['mc'] ?? null;

		return is_object($mc) ? $mc : null;
	}

	/**
	 * The global $menumanager set by main.inc.php, null when no menu is loaded (NOREQUIREMENU, CLI).
	 */
	public function menuManager(): ?object
	{
		$menu = $GLOBALS['menumanager'] ?? null;

		return is_object($menu) ? $menu : null;
	}

	/**
	 * Publish the object of the page as the global $object, which legacy helpers
	 * (dol_get_fiche_head() drag and drop, dolGetButtonAction() hooks...) read as card pages define it.
	 */
	public function setPageObject(object $object): void
	{
		$GLOBALS['object'] = $object;
	}

	/**
	 * Authentication mode of the current session ($_SESSION['dol_authmode']), '' when none.
	 * Kept for the legacy view UserCardChrome, which receives the LegacyContext; controllers and
	 * services read the session through Session.
	 */
	public function authMode(): string
	{
		$mode = $_SESSION['dol_authmode'] ?? '';

		return is_string($mode) ? $mode : '';
	}
}
