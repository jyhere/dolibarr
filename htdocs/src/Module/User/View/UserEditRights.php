<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\View;

/**
 * What the current user may change on the edited user, as the edit view of htdocs/user/card.php
 * (l.2498-3309) reads it: given by the controller from UserPermissionChecker, read by UserFormChrome.
 */
final class UserEditRights
{
	/**
	 * @param bool $canEdit         $permissiontoedit (l.164): else the fields are shown read-only
	 * @param bool $canSetPassword  $permissiontoeditpasswordandsee (l.165)
	 * @param bool $canSendPassword $permissiontoeditpasswordandsend (l.166)
	 * @param bool $canEditLogin    l.2544: an admin, on a user not managed by LDAP
	 * @param bool $canLinkCompany  l.2557: an admin on another user picks the third party and the contact
	 * @param int  $nbOfUsers       l.964-974: the hierarchy rows need more than one user
	 */
	public function __construct(
		public readonly bool $canEdit,
		public readonly bool $canSetPassword,
		public readonly bool $canSendPassword,
		public readonly bool $canEditLogin,
		public readonly bool $canLinkCompany,
		public readonly int $nbOfUsers
	) {
	}
}
