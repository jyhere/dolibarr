<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Repository;

/**
 * A page of the user list.
 */
final class UserPage
{
	/**
	 * @param list<UserListRow> $items   At most $limit rows
	 * @param int               $total   All the matching rows, -1 when not counted (MAIN_DISABLE_FULL_SCANLIST)
	 * @param int               $page    The page shown (0 when the asked one is beyond the total, list.php l.548-551)
	 * @param bool              $hasMore A next page exists (the query reads $limit + 1 rows, list.php l.558)
	 * @param string            $sql       The list query without its LIMIT ($sql of list.php), Query::toLegacySql():
	 *                                     its values interpolated as DoliDB escapes them, for the legacy consumers
	 *                                     only (the grand total of list_print_total.tpl.php, the printFieldListFooter
	 *                                     hooks), never run by the MVC core; never exposed
	 * @param string            $sqlFields Its SELECT part ($sqlfields of list.php l.419), a prefix of $sql
	 */
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly int $page,
		public readonly int $limit,
		public readonly bool $hasMore,
		public readonly string $sql = '',
		public readonly string $sqlFields = ''
	) {
	}
}
