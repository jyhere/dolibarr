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
 * SQL comments (double dash, slash-star blocks, '#') are not tokenised: MVC SQL must not contain comments.
 * A quote inside one opens a literal up to the next quote of the statement: the placeholders in between are not
 * bound (the binding throws on the unused parameter, or the engine refuses the statement); with no later quote,
 * the quote is ignored and the statement runs unchanged, comment included. A ':name' inside one is read as a
 * parameter: the native drivers (MysqliDriver, PgsqlDriver) never splice a value into the SQL, so the engine
 * refuses the statement (placeholder count), but LegacyDriver interpolates its escaped value inside the comment.
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
