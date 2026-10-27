<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * What the SQL of a Query changes by dialect. Identifiers are never quoted (Dolibarr names are lower case).
 */
interface Grammar
{
	/**
	 * 'mysql' or 'pgsql': the literal grammar (SqlLiterals) of the SQL written for it.
	 */
	public function dialect(): string;

	/**
	 * @param string $operator One of Query::OPERATORS
	 */
	public function operator(string $operator, bool $caseInsensitive): string;

	/**
	 * ' LIMIT n OFFSET m' or the part of it given, '' for none.
	 */
	public function limit(?int $limit, ?int $offset): string;

	/**
	 * A legacy fragment (MySQL syntax, from hooks and extrafields) as it must be inserted.
	 */
	public function legacyFragment(string $fragment): string;
}
