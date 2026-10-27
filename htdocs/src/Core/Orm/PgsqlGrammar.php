<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm;

/**
 * PostgreSQL. LIKE stays case sensitive, as convertSQLFromMysql() leaves it, unless Query::caseInsensitive()
 * asks for ILIKE; the 'ilike' operator is always ILIKE. Legacy fragments (MySQL syntax) go through the converter
 * of the DoliDB first; the SQL the Query generates is already PostgreSQL and is not converted.
 */
final class PgsqlGrammar implements Grammar
{
	/** @var \Closure(string): string */
	private readonly \Closure $converter;

	/**
	 * @param \Closure(string): string $converter MySQL fragment → PostgreSQL (DoliDBPgsql::convertSQLFromMysql())
	 */
	public function __construct(\Closure $converter)
	{
		$this->converter = $converter;
	}

	public static function forLegacy(\DoliDB $db): self
	{
		return new self(static fn (string $fragment): string => (string) $db->convertSQLFromMysql($fragment));
	}

	public function dialect(): string
	{
		return 'pgsql';
	}

	public function operator(string $operator, bool $caseInsensitive): string
	{
		if ($caseInsensitive && str_ends_with($operator, 'like') && !str_ends_with($operator, 'ilike')) {
			return strtoupper(substr($operator, 0, -4)).'ILIKE';
		}

		return strtoupper($operator);
	}

	public function limit(?int $limit, ?int $offset): string
	{
		return ($limit === null ? '' : ' LIMIT '.$limit).($offset === null ? '' : ' OFFSET '.$offset);
	}

	public function legacyFragment(string $fragment): string
	{
		return ($this->converter)($fragment);
	}
}
