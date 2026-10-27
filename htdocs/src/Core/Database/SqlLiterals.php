<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * The one grammar of SQL string literals shared by ParameterBinder, LegacyDriver and PgsqlDriver, so that a
 * ':name' or '?' inside a literal is never taken for a placeholder.
 *
 * mysql dialect: '…' and "…" are strings, with a doubled quote or a backslash escape inside.
 * pgsql dialect (standard_conforming_strings=on): '…' is a string, "…" an identifier, only a doubled quote
 * escapes, a backslash is an ordinary character. E'…' and $$…$$ literals are not supported in MVC SQL.
 *
 * SQL comments (double dash, slash-star blocks, '#') are not tokenised: MVC SQL carries no comment. A quote
 * inside one is read as the start of a literal, which swallows the placeholders up to the next quote, and a
 * ':name' inside one is read as a parameter: the binding then throws (unused or missing parameter) or the
 * engine refuses the statement (placeholder count). It fails safe: no value is ever spliced into the SQL.
 */
final class SqlLiterals
{
	/**
	 * Regex alternation (no delimiters, no groups) matching one literal or quoted identifier.
	 */
	public static function pattern(string $dialect): string
	{
		if ($dialect === 'pgsql') {
			return '\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"';
		}

		return '\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"';
	}
}
