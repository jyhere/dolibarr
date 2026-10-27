<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * Prepared statements on the pgsql link opened by DoliDBPgsql ($db->db) with pg_query_params(): same
 * connection, same transaction. '?' placeholders become numbered ($1, $2...) by placeholders().
 */
final class PgsqlDriver implements Driver
{
	/** String literals ('…', "…") are skipped, a '?' outside them is a placeholder (same grammar as ParameterBinder). */
	private const TOKENS = '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|\?/s';

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
	 */
	public function lastInsertId(string $table, string $column): int
	{
		$result = @pg_query_params($this->link(), 'SELECT currval($1)', [$table.'_'.$column.'_seq']);
		if ($result === false) {
			return -1;
		}
		$value = pg_fetch_result($result, 0, 0);
		pg_free_result($result);

		return (int) $value;
	}

	public function placeholders(BoundStatement $statement): BoundStatement
	{
		$position = 0;
		$sql = preg_replace_callback(
			self::TOKENS,
			static function (array $match) use (&$position): string {
				return $match[0] === '?' ? '$'.(++$position) : $match[0];
			},
			$statement->sql
		);
		if ($sql === null) {
			throw new \InvalidArgumentException('SQL could not be parsed for placeholders: '.preg_last_error_msg());
		}
		if ($position !== count($statement->values)) {
			throw new \InvalidArgumentException(count($statement->values).' bound value(s) for '.$position.' placeholder(s)');
		}

		return new BoundStatement($sql, $statement->values);
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
