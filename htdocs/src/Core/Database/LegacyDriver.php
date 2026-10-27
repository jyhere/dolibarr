<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * Fallback for a DoliDB without native driver (sqlite3, third-party): each '?' is replaced by its escaped value,
 * then DoliDB::query() runs the SQL (with its read-only mode, log and lastquery). Less safe than a prepared
 * statement but correct, and the only place of the MVC core that interpolates values into SQL.
 */
final class LegacyDriver implements Driver
{
	private const TOKENS = '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|\?/s';

	public function __construct(private readonly \DoliDB $db)
	{
	}

	public function fetchAll(BoundStatement $statement): array
	{
		$result = $this->run($statement);
		if ($result === true) {
			return [];
		}
		$rows = [];
		while (is_object($row = $this->db->fetch_object($result))) {
			/** @var array<string, mixed> $columns */
			$columns = get_object_vars($row);
			$rows[] = $columns;
		}
		$this->db->free($result);

		return $rows;
	}

	public function execute(BoundStatement $statement): int
	{
		return max(0, (int) $this->db->affected_rows($this->run($statement)));
	}

	public function lastInsertId(string $table, string $column): int
	{
		return (int) $this->db->last_insert_id($table, $column);
	}

	public function placeholders(BoundStatement $statement): BoundStatement
	{
		return $statement;
	}

	/**
	 * @throws DatabaseException
	 */
	private function run(BoundStatement $statement): mixed
	{
		$result = $this->db->query($this->interpolate($statement));
		if ($result === false) {
			throw DatabaseException::fromStatement($statement, (string) $this->db->lasterror(), (string) $this->db->lasterrno());
		}

		return $result;
	}

	/**
	 * @throws \InvalidArgumentException When placeholders and values do not match
	 */
	private function interpolate(BoundStatement $statement): string
	{
		$values = $statement->values;
		$position = 0;
		$sql = preg_replace_callback(
			self::TOKENS,
			function (array $match) use ($values, &$position): string {
				if ($match[0] !== '?') {
					return $match[0];
				}
				if (!array_key_exists($position, $values)) {
					throw new \InvalidArgumentException('More placeholders than the '.count($values).' bound value(s)');
				}

				return $this->literal($values[$position++]);
			},
			$statement->sql
		);
		if ($sql === null) {
			throw new \InvalidArgumentException('SQL could not be parsed for placeholders: '.preg_last_error_msg());
		}
		if ($position !== count($values)) {
			throw new \InvalidArgumentException(count($values).' bound value(s) for '.$position.' placeholder(s)');
		}

		return $sql;
	}

	/**
	 * A negative number is parenthesised: 'amount-?' with -5 must not become 'amount--5', a comment on
	 * PostgreSQL and SQLite.
	 */
	private function literal(int|float|string|null $value): string
	{
		if ($value === null) {
			return 'NULL';
		}
		if (is_string($value)) {
			return "'".$this->db->escape($value)."'";
		}
		$number = is_int($value) ? (string) $value : var_export($value, true);

		return $value < 0 ? '('.$number.')' : $number;
	}
}
