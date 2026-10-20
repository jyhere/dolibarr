<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * Thin wrapper over the legacy DoliDB connection; SQL errors throw DatabaseException.
 */
final class Connection
{
	public function __construct(private readonly \DoliDB $db)
	{
	}

	public function prefix(): string
	{
		return MAIN_DB_PREFIX;
	}

	public function escape(string $v): string
	{
		return $this->db->escape($v);
	}

	/**
	 * @return list<object>
	 * @throws DatabaseException
	 */
	public function fetchAll(string $sql): array
	{
		$res = $this->query($sql);
		$rows = [];
		while (($obj = $this->db->fetch_object($res)) !== null && $obj !== false) {
			$rows[] = $obj;
		}
		$this->db->free($res);

		return $rows;
	}

	/**
	 * @throws DatabaseException
	 */
	public function fetchOne(string $sql): ?object
	{
		$res = $this->query($sql);
		$obj = $this->db->fetch_object($res);
		$this->db->free($res);

		return is_object($obj) ? $obj : null;
	}

	/**
	 * @return int Affected rows
	 * @throws DatabaseException
	 */
	public function execute(string $sql): int
	{
		$res = $this->query($sql);

		return (int) $this->db->affected_rows($res);
	}

	public function lastInsertId(string $table): int
	{
		return (int) $this->db->last_insert_id($table);
	}

	public function begin(): void
	{
		$this->db->begin();
	}

	public function commit(): void
	{
		$this->db->commit();
	}

	public function rollback(): void
	{
		$this->db->rollback();
	}

	public function legacy(): \DoliDB
	{
		return $this->db;
	}

	/**
	 * @throws DatabaseException
	 */
	private function query(string $sql): mixed
	{
		$res = $this->db->query($sql);
		if ($res === false) {
			throw new DatabaseException($this->db->lasterror().' (SQL: '.$sql.')', (int) $this->db->lasterrno());
		}

		return $res;
	}
}
