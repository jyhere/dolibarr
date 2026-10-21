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

	/**
	 * DoliDB::begin(): 1 when the transaction is opened (or only its nesting level raised), 0 when BEGIN fails.
	 *
	 * @throws DatabaseException When BEGIN fails
	 */
	public function begin(): void
	{
		if (!$this->db->begin()) {
			throw new DatabaseException($this->db->lasterror(), (int) $this->db->lasterrno());
		}
	}

	/**
	 * DoliDB::commit(): 1 when committed (or only its nesting level lowered), 0 when COMMIT fails.
	 *
	 * @throws DatabaseException When COMMIT fails
	 */
	public function commit(): void
	{
		if (!$this->db->commit()) {
			throw new DatabaseException($this->db->lasterror(), (int) $this->db->lasterrno());
		}
	}

	/**
	 * DoliDB::rollback(): the result of query('ROLLBACK') (false when it fails), or 1 when only lowering the
	 * nesting level.
	 *
	 * @throws DatabaseException When ROLLBACK fails
	 */
	public function rollback(): void
	{
		if (!$this->db->rollback()) {
			throw new DatabaseException($this->db->lasterror(), (int) $this->db->lasterrno());
		}
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
