<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * MySQL/MariaDB, and the fallback driver (the legacy DoliDB accept MySQL syntax and convert it themselves).
 */
final class MysqlGrammar implements Grammar
{
	/** MySQL has no OFFSET without LIMIT: the largest row count it accepts. */
	private const NO_LIMIT = '18446744073709551615';

	public function dialect(): string
	{
		return 'mysql';
	}

	public function operator(string $operator, bool $caseInsensitive): string
	{
		return strtoupper($operator);
	}

	public function limit(?int $limit, ?int $offset): string
	{
		if ($offset === null) {
			return $limit === null ? '' : ' LIMIT '.$limit;
		}

		return ' LIMIT '.($limit ?? self::NO_LIMIT).' OFFSET '.$offset;
	}

	public function legacyFragment(string $fragment): string
	{
		return $fragment;
	}
}
