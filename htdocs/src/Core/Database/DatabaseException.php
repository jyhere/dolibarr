<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

final class DatabaseException extends \RuntimeException
{
	private string $engineCode = '';

	/**
	 * The engine message and the SQL with its placeholders, never the bound values: they may hold passwords or
	 * personal data. Bound strings an engine echoes in its message (duplicate entry, syntax error near…) are masked.
	 *
	 * @param int|string $code Code of the engine or of DoliDB ('DB_ERROR_SYNTAX', 'APPREADONLY', 1064…)
	 */
	public static function fromStatement(BoundStatement $statement, string $message, int|string $code): self
	{
		$exception = new self(self::mask($message, $statement->values).' (SQL: '.$statement->sql.')', is_numeric($code) ? (int) $code : 0);
		$exception->engineCode = (string) $code;

		return $exception;
	}

	/**
	 * The code as the engine or DoliDB gives it ('DB_ERROR_NOSUCHFIELD', 'APPREADONLY'...); getCode() is only its
	 * numeric form (0 for a symbolic code).
	 */
	public function engineCode(): string
	{
		return $this->engineCode !== '' ? $this->engineCode : (string) $this->getCode();
	}

	/**
	 * @param list<int|float|string|null> $values
	 */
	private static function mask(string $message, array $values): string
	{
		$forms = [];
		foreach ($values as $value) {
			if (!is_string($value) || strlen($value) < 3) {
				continue;
			}
			// As bound, then as escaped by mysqli (backslashes) or pgsql (doubled quotes) in an interpolated query
			$forms[] = $value;
			$forms[] = addslashes($value);
			$forms[] = str_replace("'", "''", $value);
		}
		$forms = array_unique($forms);
		usort($forms, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

		return $forms === [] ? $message : str_replace($forms, '***', $message);
	}
}
