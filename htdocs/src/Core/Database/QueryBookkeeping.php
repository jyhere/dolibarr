<?php

declare(strict_types=1);

namespace Dolibarr\Core\Database;

use Dolibarr\Core\Legacy\LegacyContext;

/**
 * What DoliDB::query() does around a query (mysqli.class.php::query() l.327-385), for the native drivers that
 * bypass it: sql log, read-only mode, and the lastquery/lasterror/lastqueryerror/lasterrno the legacy code reads.
 * Only the SQL with its placeholders is recorded, never the bound values.
 */
final class QueryBookkeeping
{
	private const SYSLOG_SQL_LIMIT = 10000;

	public function __construct(private readonly \DoliDB $db)
	{
	}

	/**
	 * @throws DatabaseException APPREADONLY for a write while $dolibarr_main_db_readonly is set
	 */
	public function start(BoundStatement $statement): void
	{
		$sql = trim($statement->sql);
		dol_syslog('sql='.substr($sql, 0, self::SYSLOG_SQL_LIMIT), LOG_DEBUG);

		if (LegacyContext::databaseReadOnly() && preg_match('/^(INSERT|UPDATE|REPLACE|DELETE|CREATE|ALTER|TRUNCATE|DROP)/i', $sql)) {
			$this->db->lasterror = 'Application in read-only mode';
			$this->db->lasterrno = 'APPREADONLY';
			$this->db->lastquery = $sql;

			throw DatabaseException::fromStatement($statement, $this->db->lasterror, $this->db->lasterrno);
		}
	}

	public function succeeded(BoundStatement $statement): void
	{
		$this->db->lastquery = trim($statement->sql);
	}

	public function failed(BoundStatement $statement, string $message, string $code): DatabaseException
	{
		$sql = trim($statement->sql);
		$this->db->lastqueryerror = $sql;
		$this->db->lasterror = $message;
		$this->db->lasterrno = $code;
		$this->db->lastquery = $sql;

		if (getDolGlobalInt('SYSLOG_LEVEL') < LOG_DEBUG) {
			dol_syslog(get_class($this->db).'::query SQL Error query: '.$sql, LOG_ERR);
		}
		dol_syslog(get_class($this->db).'::query SQL Error message: '.$code.' '.$message, LOG_ERR);

		return DatabaseException::fromStatement($statement, $message, $code);
	}
}
