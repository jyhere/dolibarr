<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

/**
 * The database of the MVC core, on the link opened by DoliDB: bound parameters run by a Driver chosen from
 * $db->type, transactions shared with the legacy code (nesting counter of DoliDB). SQL errors throw
 * DatabaseException.
 *
 * SQL: '@table' for a prefixed table, ':name' for a parameter (see ParameterBinder). Hand-written SQL must be
 * portable MySQL/PostgreSQL (no IFNULL, backquotes or LIMIT a, b): native drivers do not rewrite it.
 */
final class Connection
{
	private readonly Driver $driver;

	private readonly ParameterBinder $binder;

	/**
	 * @param Driver|null $driver Null: the one of Connection::forLegacy()
	 */
	public function __construct(private readonly \DoliDB $db, ?Driver $driver = null)
	{
		$this->driver = $driver ?? self::driverFor($db);
		$this->binder = new ParameterBinder($this->prefix(), $this->dialect());
	}

	public static function forLegacy(\DoliDB $db): self
	{
		return new self($db);
	}

	/**
	 * @param array<mixed> $params
	 * @return list<array<string, mixed>>
	 * @throws DatabaseException
	 */
	public function fetchAll(string $sql, array $params = []): array
	{
		return $this->driver->fetchAll($this->statement($sql, $params));
	}

	/**
	 * The first row, null when none (the SQL gives its own LIMIT when several rows can match).
	 *
	 * @param array<mixed> $params
	 * @return array<string, mixed>|null
	 * @throws DatabaseException
	 */
	public function fetchOne(string $sql, array $params = []): ?array
	{
		return $this->fetchAll($sql, $params)[0] ?? null;
	}

	/**
	 * The first column of the first row, null when no row.
	 *
	 * @param array<mixed> $params
	 * @throws DatabaseException
	 */
	public function fetchValue(string $sql, array $params = []): mixed
	{
		$row = $this->fetchOne($sql, $params);

		return $row === null ? null : (array_values($row)[0] ?? null);
	}

	/**
	 * @param array<mixed> $params
	 * @return int Affected rows
	 * @throws DatabaseException
	 */
	public function execute(string $sql, array $params = []): int
	{
		return $this->driver->execute($this->statement($sql, $params));
	}

	/**
	 * @param string $table Table name without prefix ('user', or '@user')
	 * @throws \InvalidArgumentException When the name already holds the prefix ('llx_user')
	 */
	public function lastInsertId(string $table, string $column = 'rowid'): int
	{
		$name = ltrim($table, '@');
		if ($this->prefix() !== '' && str_starts_with($name, $this->prefix())) {
			throw new \InvalidArgumentException('lastInsertId() takes the table without prefix, not "'.$table.'"');
		}

		return $this->driver->lastInsertId($this->prefix().$name, $column);
	}

	/**
	 * Runs $work between begin() and commit(), inside the transaction already opened by the legacy code or a
	 * service if any. On failure, rolls back and rethrows; a failed ROLLBACK keeps the exception of the work
	 * as its previous one.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 * @throws DatabaseException When BEGIN, COMMIT or ROLLBACK fails
	 */
	public function transactional(callable $work): mixed
	{
		$this->begin();
		try {
			$result = $work();
			$this->commit();
		} catch (\Throwable $e) {
			try {
				$this->rollback();
			} catch (DatabaseException $rollback) {
				throw new DatabaseException('ROLLBACK failed: '.$rollback->getMessage(), $rollback->getCode(), $e);
			}
			throw $e;
		}

		return $result;
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

	public function prefix(): string
	{
		return MAIN_DB_PREFIX;
	}

	/**
	 * 'pgsql' for PostgreSQL, 'mysql' otherwise (MySQL/MariaDB; the other DoliDB accept MySQL syntax).
	 */
	public function dialect(): string
	{
		return $this->db->type === 'pgsql' ? 'pgsql' : 'mysql';
	}

	public function driver(): Driver
	{
		return $this->driver;
	}

	public function legacy(): \DoliDB
	{
		return $this->db;
	}

	/**
	 * Legacy API, removed with the legacy* methods: bind parameters instead.
	 */
	public function escape(string $value): string
	{
		return $this->db->escape($value);
	}

	/**
	 * Legacy API (SQL built by concatenation, rows as objects), to be removed: use fetchAll().
	 * @return list<object>
	 * @throws DatabaseException
	 */
	public function legacyFetchAllObjects(string $sql): array
	{
		$res = $this->legacyQuery($sql);
		$rows = [];
		while (($obj = $this->db->fetch_object($res)) !== null && $obj !== false) {
			$rows[] = $obj;
		}
		$this->db->free($res);

		return $rows;
	}

	/**
	 * Legacy API (SQL built by concatenation, row as object), to be removed: use fetchOne().
	 * @throws DatabaseException
	 */
	public function legacyFetchOneObject(string $sql): ?object
	{
		$res = $this->legacyQuery($sql);
		$obj = $this->db->fetch_object($res);
		$this->db->free($res);

		return is_object($obj) ? $obj : null;
	}

	/**
	 * Legacy API (SQL built by concatenation), to be removed: use execute().
	 * @return int Affected rows
	 * @throws DatabaseException
	 */
	public function legacyExecute(string $sql): int
	{
		$res = $this->legacyQuery($sql);

		return (int) $this->db->affected_rows($res);
	}

	/**
	 * Native drivers for MySQL/MariaDB and PostgreSQL, the interpolating fallback for any other DoliDB.
	 */
	private static function driverFor(\DoliDB $db): Driver
	{
		return match ($db->type) {
			'mysqli' => new MysqliDriver($db),
			'pgsql' => new PgsqlDriver($db),
			default => new LegacyDriver($db),
		};
	}

	/**
	 * @param array<mixed> $params
	 */
	private function statement(string $sql, array $params): BoundStatement
	{
		return $this->driver->placeholders($this->binder->bind($sql, $params));
	}

	/**
	 * @throws DatabaseException
	 */
	private function legacyQuery(string $sql): mixed
	{
		$res = $this->db->query($sql);
		if ($res === false) {
			throw new DatabaseException($this->db->lasterror().' (SQL: '.$sql.')', (int) $this->db->lasterrno());
		}

		return $res;
	}
}
