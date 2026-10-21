<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Repository;

use Dolibarr\Module\User\Entity\User;

/**
 * A row of the user list: the user and the joined columns list.php l.398-406 selects for its extra columns.
 *
 * $row is the raw row (the $obj of list.php, with the legacy aliases, the extrafields options_* and the
 * columns added by the printFieldListSelect hooks), given to the legacy row helpers and hooks only. It holds
 * the encrypted api_key when the search was asked for it: never exposed (the JSON goes through UserView).
 */
final class UserListRow
{
	/**
	 * @param array<string, mixed> $row
	 */
	public function __construct(
		public readonly User $user,
		public readonly ?string $supervisorLogin,
		public readonly ?string $supervisorFirstname,
		public readonly ?string $supervisorLastname,
		public readonly ?string $companyName,
		public readonly ?string $countryCode,
		public readonly ?string $countryLabel,
		public readonly ?\DateTimeImmutable $dateModification,
		public readonly array $row
	) {
	}
}
