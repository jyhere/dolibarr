<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Repository;

use Dolibarr\Core\Config;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Module\User\Entity\RightDefinition;
use Dolibarr\Module\User\View\RightsChrome;

/**
 * The rights catalogue of the rights tab (htdocs/user/perms.php) and the rights of a user. The module
 * descriptors (and the llx_rights_def fixes perms.php makes on every display) come from RightsChrome.
 */
final class RightsRepository
{
	public function __construct(private readonly Connection $c, private readonly RightsChrome $chrome, private readonly Config $config)
	{
	}

	/**
	 * perms.php l.527-595 (rights of the entity, without 'tou%' labels, without the *_advance ones in simple
	 * mode, sorted by family position, module position and id) for the modules having a descriptor
	 * (l.601-618), with the grants of $userId (l.352-406). The modules in the order of their first right.
	 *
	 * The group rights by entity (perms.php's $permsgroupbyentity, given to the insertExtraHeader hook) come with it.
	 */
	public function catalogFor(int $userId, int $entity, bool $advancedPerms): RightsCatalog
	{
		$modules = $this->chrome->moduleDescriptors($entity);
		$granted = array_fill_keys($this->userRights($userId, $entity), true);
		$byEntity = $this->groupRightsByEntity($userId, $entity);
		$inherited = array_fill_keys([...($byEntity[0] ?? []), ...($entity !== 0 ? ($byEntity[$entity] ?? []) : [])], true);

		$sql = 'SELECT r.id, r.libelle as label, r.module, r.module_origin, r.perms, r.subperms, r.module_position, r.right_position, r.bydefault, r.family, r.family_position';
		$sql .= ' FROM '.$this->c->prefix().'rights_def as r';
		$sql .= " WHERE r.libelle NOT LIKE 'tou%'";
		$sql .= ' AND r.entity = '.$entity;
		if (!$advancedPerms) {
			$sql .= " AND r.perms NOT LIKE '%_advance'";
		}
		$sql .= ' ORDER BY r.family_position, r.module_position, r.right_position, r.module, r.id';

		// l.562-589: the family and the positions completed, then the sort key
		$rows = [];
		foreach ($this->c->fetchAll($sql) as $obj) {
			$family = (string) ($obj->family ?? '');
			$family = $family === '' ? 'other' : (isset(RightsChrome::FAMILY_POSITIONS[$family]) ? $family : 'external');
			$familyPosition = (string) ($obj->family_position ?? '');
			$modulePosition = (int) $obj->module_position;
			if (empty($obj->family_position)) {
				$familyPosition = RightsChrome::FAMILY_POSITIONS[$family];
				if ($modulePosition < 100000) {
					$modulePosition += 100000;
				}
			}
			$rows[] = ['position' => $familyPosition.'_'.$modulePosition.'_'.$obj->id, 'familyPosition' => (int) $familyPosition, 'modulePosition' => $modulePosition, 'obj' => $obj];
		}
		// l.595: dol_sort_array($arrayofpermission, 'position'), an asort() of the keys: stable, string order
		usort($rows, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

		$reception = $this->config->isModuleEnabled('reception');
		// Grouped by module: perms.php prints a break line at each change of module (l.640), so it would repeat a
		// module whose rights interleave with another's in position order; here they stay under one break line.
		$catalog = [];
		foreach ($rows as $row) {
			$obj = $row['obj'];
			$module = (string) $obj->module;
			$subperms = $obj->subperms !== null && $obj->subperms !== '' ? (string) $obj->subperms : null;
			if (!isset($modules[$module]) || ($reception && RightsChrome::replacedByReception($module, (string) $obj->perms, (string) $subperms))) {
				continue;
			}
			$id = (int) $obj->id;
			$origin = $obj->module_origin !== null && $obj->module_origin !== '' ? (string) $obj->module_origin : null;
			$catalog[$module][] = new RightDefinition(
				$id,
				$module,
				(string) $obj->perms,
				$subperms,
				(string) $obj->label,
				$row['familyPosition'],
				$row['modulePosition'],
				(int) $obj->right_position,
				isset($granted[$id]),
				isset($inherited[$id]),
				$origin
			);
		}

		return new RightsCatalog($catalog, $byEntity);
	}

	/**
	 * perms.php l.352-370: the ids of the rights granted to the user himself in the entity.
	 *
	 * @return list<int>
	 */
	public function userRights(int $userId, int $entity): array
	{
		$ids = [];
		foreach ($this->c->fetchAll('SELECT ur.fk_id FROM '.$this->c->prefix().'user_rights as ur WHERE ur.entity = '.$entity.' AND ur.fk_user = '.$userId) as $row) {
			$ids[] = (int) $row->fk_id;
		}

		return $ids;
	}

	/**
	 * perms.php l.372-406 ($permsgroupbyentity): the ids of the rights the user has from his groups, by
	 * entity of the group membership.
	 *
	 * @return array<int, list<int>>
	 */
	public function groupRightsByEntity(int $userId, int $entity): array
	{
		$prefix = $this->c->prefix();
		$sql = 'SELECT DISTINCT gr.fk_id, gu.entity';
		$sql .= ' FROM '.$prefix.'usergroup_rights as gr, '.$prefix.'usergroup_user as gu';
		$sql .= ' WHERE gr.entity = '.$entity;
		// if (getDolGlobalString(...)): '' and '0' are false
		$fix = $this->config->string('MULTICOMPANY_TRANSVERSE_MODE_FIX_WHEN_GU_CONTAINS_0');
		$sql .= $fix !== '' && $fix !== '0' ? ' AND gu.entity IN (0,'.$entity.')' : ' AND gu.entity = '.$entity;
		$sql .= ' AND gr.fk_usergroup = gu.fk_usergroup';
		$sql .= ' AND gu.fk_user = '.$userId;

		$byEntity = [];
		foreach ($this->c->fetchAll($sql) as $row) {
			$byEntity[(int) $row->entity][] = (int) $row->fk_id;
		}

		return $byEntity;
	}
}
