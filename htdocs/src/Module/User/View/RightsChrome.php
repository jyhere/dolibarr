<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\View;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Module\User\Entity\RightDefinition;

/**
 * The legacy parts of the rights tab (htdocs/user/perms.php): the module descriptors and the
 * legacy-rendered pictos and boxes of the page. The only place of the module instantiating the
 * descriptors htdocs/core/modules/mod*.class.php.
 *
 * Legacy behaviour kept: like perms.php on every display, moduleDescriptors() WRITES to llx_rights_def
 * (insert_permissions() adds the missing rights of the enabled modules, l.237-243, and the wrong
 * module_position and empty family are fixed, l.258-330). These are the only writes of a GET of the tab.
 */
final class RightsChrome
{
	/** perms.php l.536-550: positions of the families of modules */
	public const FAMILY_POSITIONS = [
		'hr' => '001', 'crm' => '006', 'srm' => '007', 'financial' => '009', 'products' => '012', 'projects' => '015',
		'ecm' => '018', 'technic' => '021', 'portal' => '040', 'interface' => '050', 'base' => '060', 'other' => '100',
		'external' => '500',
	];

	/** @var array<int, array<string, \DolibarrModules>> The descriptors by entity, scanned once per request */
	private array $descriptors = [];

	public function __construct(private readonly LegacyContext $ctx, private readonly Connection $connection)
	{
	}

	/**
	 * perms.php l.171-330: the descriptors of the modules having rights, by rights_class. Each one's
	 * language files are loaded; the rights of the enabled ones are inserted into llx_rights_def when
	 * missing, then the positions and families of llx_rights_def are fixed (see the class comment).
	 * The old or the new supplier descriptors are ignored depending on MAIN_USE_NEW_SUPPLIERMOD (l.178).
	 *
	 * @return array<string, \DolibarrModules>
	 */
	public function moduleDescriptors(int $entity): array
	{
		if (isset($this->descriptors[$entity])) {
			return $this->descriptors[$entity];
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';
		$db = $this->ctx->db;

		$modules = $this->connection->transactional(function () use ($db, $entity): array {
			$modules = [];
			$excluded = getDolGlobalInt('MAIN_USE_NEW_SUPPLIERMOD') ? ['modFournisseur'] : ['modSupplierOrder', 'modSupplierInvoice'];
			$enabled = $this->enabledModuleConstants($entity);
			// l.201-211: the ids already in llx_rights_def, so insert_permissions() checks them in memory
			$existing = [];
			foreach ($this->connection->fetchAll('SELECT id FROM @rights_def WHERE entity = :entity', ['entity' => $entity]) as $row) {
				$existing[(int) $row['id']] = 1;
			}

			foreach (dolGetModulesDirs() as $dir) {
				$handle = @opendir(dol_osencode($dir));
				if (!is_resource($handle)) {
					continue;
				}
				while (($file = readdir($handle)) !== false) {
					if (!is_readable($dir.$file) || !str_starts_with($file, 'mod') || !str_ends_with($file, '.class.php')) {
						continue;
					}
					$modName = substr($file, 0, -10);
					if ($modName === '' || in_array($modName, $excluded, true)) {
						continue;
					}
					include_once $dir.$file;
					if (!class_exists($modName, false) || !is_subclass_of($modName, \DolibarrModules::class)) {
						continue;
					}
					$objMod = new $modName($db);

					// l.231-236
					if (is_array($objMod->langfiles ?? null)) {
						foreach ($objMod->langfiles as $domain) {
							$this->ctx->langs->load((string) $domain);
						}
					}
					// l.237-246: the enablement is known from the preload, insert_permissions() is only called when enabled
					$rightsClass = (string) $objMod->rights_class;
					if ($rightsClass !== '') {
						$constName = (string) $objMod->const_name;
						if ($constName === '' || !empty($enabled[$constName])) {
							$objMod->insert_permissions(0, $entity, 0, $existing);
						}
						$modules[$rightsClass] = $objMod;
					}
				}
				closedir($handle);
			}

			return $modules;
		});

		$this->fixPositions($modules, $entity);

		return $this->descriptors[$entity] = $modules;
	}

	/**
	 * The display data of each module having rights: picto (perms.php l.655, 'generic' when none), name
	 * (getName()), rights_admin_allowed (the rights an admin has without grant, l.667 and l.732) and the
	 * position of its family (l.536-550, 'other' for none, 'external' for an unknown one).
	 *
	 * @return array<string, array{picto: string, name: string, adminAllowed: bool, familyPosition: int}>
	 */
	public function moduleMeta(int $entity): array
	{
		$meta = [];
		foreach ($this->moduleDescriptors($entity) as $module => $objMod) {
			$family = (string) $objMod->family;
			$family = $family === '' ? 'other' : (isset(self::FAMILY_POSITIONS[$family]) ? $family : 'external');
			$picto = (string) $objMod->picto;
			$meta[$module] = [
				'picto' => $picto !== '' ? $picto : 'generic',
				'name' => (string) $objMod->getName(),
				'adminAllowed' => !empty($objMod->rights_admin_allowed),
				'familyPosition' => (int) self::FAMILY_POSITIONS[$family],
			];
		}

		return $meta;
	}

	/**
	 * The pictos and legacy boxes of the page (perms.php l.478-495, l.520-522, l.703-706, l.732-805).
	 *
	 * @return array{infoAdminHtml: string, externalWarningHtml: string, expandAllPicto: string, collapseAllPicto: string, folderPicto: string, folderOpenPicto: string, adminPicto: string, activePicto: string, removePicto: string, addPicto: string, disabledPicto: string, inheritedHtml: string}
	 */
	public function fragments(\User $object, bool $meIsAdmin, bool $advancedPerms, int $entity): array
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$langs = $this->ctx->langs;
		$form = $this->ctx->form();

		// l.478-490
		$infoAdmin = '';
		if ($meIsAdmin) {
			$warning = $langs->trans('WarningOnlyPermissionOfActivatedModules').' '.$langs->trans('YouCanEnableModulesFrom');
			$warning .= '<br>'.img_picto($langs->trans('InfoAdmin'), 'info-circle').' ';
			$warning .= $langs->trans($advancedPerms ? 'YouAreUsingTheAdvancedPermissionsMode' : 'YouAreUsingTheSimplePermissionsMode');
			$infoAdmin = info_admin($warning);
		}

		return [
			'infoAdminHtml' => $infoAdmin,
			// l.491-494: the modules opened to external users
			'externalWarningHtml' => !empty($object->socid) ? info_admin(showModulesExludedForExternal($this->moduleDescriptors($entity)))."\n" : '',
			'expandAllPicto' => img_picto('', 'folder-open', 'class="paddingright"'),
			'collapseAllPicto' => img_picto('', 'folder', 'class="paddingright"'),
			'folderPicto' => img_picto('', 'folder', 'class="marginright"'),
			'folderOpenPicto' => img_picto('', 'folder-open', 'class="marginright"'),
			'adminPicto' => img_picto($langs->trans('AdministratorDesc'), 'admin', 'class="paddingleft valignmiddle"'),
			'activePicto' => img_picto($langs->trans('Active'), 'switch_on', '', 0, 0, 0, '', 'opacitymedium'),
			'removePicto' => img_picto($langs->trans('Remove'), 'switch_on'),
			'addPicto' => img_picto($langs->trans('Add'), 'switch_off'),
			'disabledPicto' => img_picto($langs->trans('Disabled'), 'switch_off', '', 0, 0, 0, '', 'opacitymedium'),
			'inheritedHtml' => $form->textwithtooltip($langs->trans('Inherited'), $langs->trans('PermissionInheritedFromAGroup')),
		];
	}

	/**
	 * perms.php l.662: the picto of a module on its break line.
	 */
	public function modulePicto(string $picto): string
	{
		return img_object('', $picto, 'class="pictoobjectwidth paddingright"');
	}

	/**
	 * The texts of one right (perms.php l.713-871): the translation keys of its label and descriptions, the
	 * badge of the module declaring it, the warnings, the id tooltip shown to admins.
	 *
	 * @param bool $hasIt The user has it as admin, by grant or from a group (the warnings of l.831-845)
	 * @return array{labelKey: string, descriptionKeys: list<string>, advancedOnly: bool, badgeHtml: string, warningsHtml: string, idHtml: string}
	 */
	public function rightTexts(RightDefinition $right, \User $object, bool $hasIt, bool $meIsAdmin, bool $advancedPerms, int $entity): array
	{
		$langs = $this->ctx->langs;
		$exists = static fn (string $key): bool => $langs->trans($key) != $key;

		// l.713
		$labelKey = $right->label;
		if ($advancedPerms && $exists('PermissionAdvanced'.$right->id)) {
			$labelKey = 'PermissionAdvanced'.$right->id;
		} elseif ($exists('Permission'.$right->id)) {
			$labelKey = 'Permission'.$right->id;
		}

		// l.718-722
		$badge = '';
		$modules = $this->moduleDescriptors($entity);
		$origin = $right->moduleOrigin;
		if ($origin !== null && $origin !== '' && $origin !== $right->module && isset($modules[$origin])) {
			$originMod = $modules[$origin];
			$originPicto = (string) $originMod->picto;
			$badge = img_picto($langs->trans('RightProvidedByModule', $originMod->getName()), $originPicto !== '' ? $originPicto : 'generic', 'class="paddingrightonly"');
		}

		// l.815-824: the 3 permissions on third parties share the description of 122
		$descriptions = [];
		$idToUse = in_array($right->id, [121, 122, 125, 126], true) ? 122 : $right->id;
		if ($exists('Permission'.$idToUse.'b')) {
			$descriptions[] = 'Permission'.$idToUse.'b';
		}
		if ($exists('Permission'.$right->id.'c')) {
			$descriptions[] = 'Permission'.$right->id.'c';
		}

		// l.830-855
		$warnings = '';
		if ($right->module === 'user' && $right->perms === 'user' && $right->subperms === 'password' && $hasIt) {
			$warnings .= ' '.img_warning($langs->trans('AllowPasswordResetBySendingANewPassByEmail'));
		}
		if ($right->module === 'user' && $right->perms === 'user' && ($right->subperms === 'creer' || $right->subperms === 'create') && $hasIt) {
			$warnings .= ' '.img_warning($langs->trans('AllowAnyPrivileges'));
		}
		if ($right->module === 'cron' && $right->perms === 'create') {
			$warnings .= ' '.img_warning($langs->trans('YouAlsoNeedToBeAdminForThisPermission'), '', 'colorgrey');
		}
		if ($right->module === 'banque' && $right->perms === 'lire' && isModEnabled('accounting') && $object->hasRight('accounting', 'chartofaccount')) {
			$warnings .= ' '.img_warning($langs->trans('WarningReadBankAlsoAllowedIfUserHasPermission'));
		}

		// l.859-871: the id and the hasRight() call, checked against module_origin when set
		$idHtml = '';
		if ($meIsAdmin) {
			$htmltext = $langs->trans('ID').': '.$right->id;
			$checked = $origin !== null && $origin !== '' ? $origin : $right->module;
			$htmltext .= '<br>'.$langs->trans('Permission').': user->hasRight(\''.dol_escape_htmltag($checked).'\', \''.dol_escape_htmltag($right->perms).'\''.($right->subperms !== null && $right->subperms !== '' ? ', \''.dol_escape_htmltag($right->subperms).'\'' : '').')';
			$idHtml = $this->ctx->form()->textwithpicto('', $htmltext, 1, 'help', 'inline-block marginrightonly');
		}

		return [
			'labelKey' => $labelKey,
			'descriptionKeys' => $descriptions,
			'advancedOnly' => $advancedPerms && str_ends_with($right->perms, '_advance'),
			'badgeHtml' => $badge,
			'warningsHtml' => $warnings,
			'idHtml' => $idHtml,
		];
	}

	/**
	 * perms.php l.180-199: the MAIN_MODULE_* constants of the entity, from $conf->global for the current one.
	 *
	 * @return array<string, mixed>
	 */
	private function enabledModuleConstants(int $entity): array
	{
		if ($entity === (int) $this->ctx->conf->entity) {
			return (array) $this->ctx->conf->global;
		}
		$db = $this->ctx->db;
		$constants = [];
		// decrypt(): the SQL expression of DoliDB for an encrypted column (the column name, or AES_DECRYPT() with the key of conf.php)
		$rows = $this->connection->fetchAll('SELECT '.$db->decrypt('name').' as name, '.$db->decrypt('value').' as value FROM @const WHERE entity IN (0, :entity) ORDER BY entity', ['entity' => $entity]);
		foreach ($rows as $row) {
			$constants[(string) $row['name']] = $row['value'];
		}

		return $constants;
	}

	/**
	 * perms.php l.258-330: module_position (empty, or under 100000 for an external module) and family (empty)
	 * of llx_rights_def fixed from the descriptors; the rights of a removed module, and the two vendor
	 * rights replaced by the reception module when it is enabled, are skipped.
	 *
	 * @param array<string, \DolibarrModules> $modules
	 */
	private function fixPositions(array $modules, int $entity): void
	{
		// DoliDBPgsql turns the LIKE of perms.php into ILIKE: the same case insensitive match on both engines
		$like = $this->connection->dialect() === 'pgsql' ? 'ILIKE' : 'LIKE';
		$sql = 'SELECT r.id, r.libelle as label, r.module, r.perms, r.subperms, r.module_position, r.family, r.family_position, r.bydefault';
		$sql .= ' FROM @rights_def as r';
		$sql .= " WHERE r.libelle NOT ".$like." 'tou%'";
		$sql .= ' AND r.entity = :entity';
		$sql .= ' ORDER BY r.family, r.family_position, r.module_position, r.right_position, r.module, r.id';

		$reception = isModEnabled('reception');
		foreach ($this->connection->fetchAll($sql, ['entity' => $entity]) as $row) {
			$module = (string) ($row['module'] ?? '');
			if ($module === '' || !isset($modules[$module])) {
				continue;
			}
			if ($reception && self::replacedByReception($module, (string) $row['perms'], (string) ($row['subperms'] ?? ''))) {
				continue;
			}
			$objMod = $modules[$module];
			$external = $objMod->isCoreOrExternalModule() == 'external';

			if (empty($row['module_position']) || ($external && (int) $row['module_position'] < 100000)) {
				if ((int) $objMod->module_position > 0) {
					$newPosition = (int) $objMod->module_position;
					if ($external && $newPosition < 100000) {
						$newPosition += 100000;
					}
					$this->connection->execute(
						'UPDATE @rights_def SET module_position = :position, family_position = 0 WHERE module_position = :current AND module = :module',
						['position' => $newPosition, 'current' => (int) $row['module_position'], 'module' => $module]
					);
				}
			}

			if (empty($row['family']) && !empty($objMod->family)) {
				$this->connection->execute('UPDATE @rights_def SET family = :family WHERE id = :id', ['family' => (string) $objMod->family, 'id' => (int) $row['id']]);
			}
		}
	}

	/**
	 * perms.php l.281-291 and l.608-618: with the reception module, these vendor rights are replaced by its own.
	 */
	public static function replacedByReception(string $module, string $perms, string $subperms): bool
	{
		return $module === 'fournisseur' && (($perms === 'commande' && $subperms === 'receptionner') || ($perms === 'commande_advance' && $subperms === 'check'));
	}
}
