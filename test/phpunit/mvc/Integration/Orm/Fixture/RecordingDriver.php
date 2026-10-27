<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm\Fixture;

use Dolibarr\Core\Database\BoundStatement;
use Dolibarr\Core\Database\Driver;

/**
 * The real driver, recording the SQL of each statement it runs (BEGIN/COMMIT go through DoliDB, not here).
 */
final class RecordingDriver implements Driver
{
	/** @var list<string> */
	public array $statements = [];

	public function __construct(private readonly Driver $driver)
	{
	}

	public function fetchAll(BoundStatement $statement): array
	{
		$this->statements[] = $statement->sql;

		return $this->driver->fetchAll($statement);
	}

	public function execute(BoundStatement $statement): int
	{
		$this->statements[] = $statement->sql;

		return $this->driver->execute($statement);
	}

	public function lastInsertId(string $table, string $column): int
	{
		return $this->driver->lastInsertId($table, $column);
	}

	public function placeholders(BoundStatement $statement): BoundStatement
	{
		return $this->driver->placeholders($statement);
	}
}
