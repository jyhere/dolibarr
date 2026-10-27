<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * Runs a bound statement on the link opened by DoliDB (never a second connection). Implementations keep what
 * DoliDB::query() does around a query: read-only mode, sql log, lastquery/lasterror of the DoliDB object.
 *
 * PostgreSQL types parameters on the server, unlike MySQL: PgsqlDriver casts a PHP int to '$1::integer'
 * ('::bigint' outside the int4 range) and a PHP float to '$1::numeric', as PostgreSQL types the number literals
 * the legacy interpolation produced, so 'amount-:x', 'rowid = :id', 'LIMIT :limit' work with either engine.
 * Strings and null stay untyped (like '…' and NULL literals): in a position without context, such as
 * ':x IS NULL' or 'COALESCE(:x, :y)', write an explicit CAST(:x AS …) in the SQL. String literals follow
 * SqlLiterals (pgsql: no backslash escape, no E'…' nor $$…$$).
 *
 * Read values are the engine ones (strings, or native int/float with mysqli prepared statements): callers cast.
 */
interface Driver
{
	/**
	 * @return list<array<string, mixed>>
	 * @throws DatabaseException
	 */
	public function fetchAll(BoundStatement $statement): array;

	/**
	 * @return int Affected rows
	 * @throws DatabaseException
	 */
	public function execute(BoundStatement $statement): int;

	/**
	 * @param string $table Full table name (prefix included)
	 */
	public function lastInsertId(string $table, string $column): int;

	/**
	 * The statement with the placeholders of the engine (pgsql: '$1', '$2'...; others: '?', unchanged).
	 */
	public function placeholders(BoundStatement $statement): BoundStatement;
}
