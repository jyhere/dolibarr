<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * Prepared statements on the mysqli link opened by DoliDBMysqli ($db->db): same connection, same transaction.
 */
final class MysqliDriver implements Driver
{
	private readonly QueryBookkeeping $bookkeeping;

	public function __construct(private readonly \DoliDB $db)
	{
		$this->bookkeeping = new QueryBookkeeping($db);
	}

	public function fetchAll(BoundStatement $statement): array
	{
		$prepared = $this->run($statement);
		$result = $prepared->get_result();
		$prepared->close();
		if ($result === false) {
			return [];
		}
		/** @var list<array<string, mixed>> $rows */
		$rows = $result->fetch_all(MYSQLI_ASSOC);
		$result->free();

		return $rows;
	}

	public function execute(BoundStatement $statement): int
	{
		$prepared = $this->run($statement);
		$affected = $prepared->affected_rows;
		$prepared->close();

		return max(0, (int) $affected);
	}

	public function lastInsertId(string $table, string $column): int
	{
		return (int) $this->link()->insert_id;
	}

	public function placeholders(BoundStatement $statement): BoundStatement
	{
		return $statement;
	}

	/**
	 * @throws DatabaseException
	 */
	private function run(BoundStatement $statement): \mysqli_stmt
	{
		$this->bookkeeping->start($statement);
		$link = $this->link();
		try {
			$prepared = $link->prepare($statement->sql);
			if ($prepared === false) {
				throw $this->failure($statement, $link->error, $link->errno);
			}
			if ($statement->values !== []) {
				$values = $statement->values;
				$prepared->bind_param($this->types($values), ...$values);
			}
			if (!$prepared->execute()) {
				throw $this->failure($statement, $prepared->error, $prepared->errno);
			}
		} catch (\mysqli_sql_exception $e) {
			throw $this->failure($statement, $e->getMessage(), $e->getCode());
		}
		$this->bookkeeping->succeeded($statement);

		return $prepared;
	}

	/**
	 * @param list<int|float|string|null> $values
	 */
	private function types(array $values): string
	{
		$types = '';
		foreach ($values as $value) {
			$types .= match (true) {
				is_int($value) => 'i',
				is_float($value) => 'd',
				default => 's',
			};
		}

		return $types;
	}

	/**
	 * The DoliDB code ('DB_ERROR_NOSUCHFIELD'...) when the link holds the error, as DoliDB::query() records it.
	 */
	private function failure(BoundStatement $statement, string $message, int $errno): DatabaseException
	{
		$code = $this->link()->errno === $errno ? (string) $this->db->errno() : 'DB_ERROR_'.$errno;

		return $this->bookkeeping->failed($statement, $message, $code);
	}

	private function link(): \mysqli
	{
		$link = $this->db->db;
		if (!$link instanceof \mysqli) {
			throw new \LogicException('MysqliDriver needs the mysqli link of a connected DoliDBMysqli');
		}

		return $link;
	}
}
