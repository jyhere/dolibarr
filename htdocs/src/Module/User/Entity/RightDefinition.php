<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Entity;

/**
 * One permission of the rights tab (a row of llx_rights_def, htdocs/user/perms.php l.527-595), with its state
 * for one user: granted to the user himself (llx_user_rights), or inherited from one of his groups
 * (llx_usergroup_rights, l.375-406).
 *
 * $label is the raw llx_rights_def.libelle (the translation key is chosen by the view, perms.php l.713);
 * $moduleOrigin is the module declaring a right filed into the section of $module (KEY_MODULE), null for a
 * native right. The positions are the ones the rights are sorted by (l.576-585).
 */
final class RightDefinition
{
	public function __construct(
		public readonly int $id,
		public readonly string $module,
		public readonly string $perms,
		public readonly ?string $subperms,
		public readonly string $label,
		public readonly int $familyPosition,
		public readonly int $modulePosition,
		public readonly int $rightPosition,
		public readonly bool $granted,
		public readonly bool $inheritedFromGroup,
		public readonly ?string $moduleOrigin = null
	) {
	}

	/**
	 * The plain array of the JSON exposure.
	 *
	 * @return array{id: int, perms: string, subperms: ?string, label: string, granted: bool, inherited: bool}
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'perms' => $this->perms,
			'subperms' => $this->subperms,
			'label' => $this->label,
			'granted' => $this->granted,
			'inherited' => $this->inheritedFromGroup,
		];
	}
}
