<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * Prepared statements on the pgsql link opened by DoliDBPgsql ($db->db) with pg_query_params(): same
 * connection, same transaction. '?' placeholders become numbered ($1, $2...) by placeholders().
 */
final class PgsqlDriver implements Driver
{
	private readonly QueryBookkeeping $bookkeeping;

	public function __construct(private readonly \DoliDB $db)
	{
		$this->bookkeeping = new QueryBookkeeping($db);
	}

	public function fetchAll(BoundStatement $statement): array
	{
		$result = $this->run($statement);
		/** @var list<array<string, mixed>> $rows */
		$rows = pg_fetch_all($result, PGSQL_ASSOC);
		pg_free_result($result);

		return $rows;
	}

	public function execute(BoundStatement $statement): int
	{
		$result = $this->run($statement);
		$affected = pg_affected_rows($result);
		pg_free_result($result);

		return max(0, $affected);
	}

	/**
	 * As DoliDBPgsql::last_insert_id(): currval() of the sequence '<table>_<column>_seq'.
	 *
	 * @throws DatabaseException When the sequence has no value in this session (legacy returned -1)
	 */
	public function lastInsertId(string $table, string $column): int
	{
		$result = $this->run(new BoundStatement('SELECT currval($1)', [$table.'_'.$column.'_seq']));
		$value = pg_fetch_result($result, 0, 0);
		pg_free_result($result);

		return (int) $value;
	}

	/**
	 * '?' outside literals becomes '$1', '$2'..., followed by '::integer'/'::bigint' for an int and '::numeric' for a
	 * float value (see Driver).
	 */
	public function placeholders(BoundStatement $statement): BoundStatement
	{
		$values = $statement->values;
		$position = 0;
		$sql = preg_replace_callback(
			'/'.SqlLiterals::pattern('pgsql').'|\\?/s',
			static function (array $match) use ($values, &$position): string {
				if ($match[0] !== '?') {
					return $match[0];
				}
				$placeholder = '$'.($position + 1);
				$value = $values[$position++] ?? null;
				if (is_int($value)) {
					return $placeholder.($value >= -2147483648 && $value <= 2147483647 ? '::integer' : '::bigint');
				}

				return is_float($value) ? $placeholder.'::numeric' : $placeholder;
			},
			$statement->sql
		);
		if ($sql === null) {
			throw new \InvalidArgumentException('SQL could not be parsed for placeholders: '.preg_last_error_msg());
		}
		if ($position !== count($values)) {
			throw new \InvalidArgumentException(count($values).' bound value(s) for '.$position.' placeholder(s)');
		}

		return new BoundStatement($sql, $values);
	}

	/**
	 * @return \PgSql\Result
	 * @throws DatabaseException
	 */
	private function run(BoundStatement $statement): \PgSql\Result
	{
		$this->bookkeeping->start($statement);
		$link = $this->link();
		$result = @pg_query_params($link, $statement->sql, $statement->values);
		if ($result === false) {
			throw $this->bookkeeping->failed($statement, pg_last_error($link), (string) $this->db->errno());
		}
		$this->bookkeeping->succeeded($statement);

		return $result;
	}

	private function link(): \PgSql\Connection
	{
		$link = $this->db->db;
		if (!$link instanceof \PgSql\Connection) {
			throw new \LogicException('PgsqlDriver needs the pgsql link of a connected DoliDBPgsql');
		}

		return $link;
	}
}
