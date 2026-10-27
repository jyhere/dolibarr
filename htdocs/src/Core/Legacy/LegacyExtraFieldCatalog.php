<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Logger;
use Dolibarr\Core\Orm\ExtraFieldCatalog;
use Dolibarr\Core\Orm\Orm;

/**
 * The extra fields of llx_extrafields, filtered as ExtraFields::fetch_name_optionals_label() does: the definitions
 * of the element type for entity 0 or the current entity ($conf->entity), by position; a separator ('separate')
 * has no column and is left out. A name that cannot be an unquoted column, or that is a technical or secret
 * column (Orm::isExtraFieldName()), is left out with a warning in the log: the queries never fail on it.
 *
 * Names are kept exactly as stored, as the legacy creates the column (PostgreSQL folds it to lower case; the ORM
 * maps the column back to the stored name). One statement per element type, cached for the request (one
 * instance per request: the container's Orm).
 */
final class LegacyExtraFieldCatalog implements ExtraFieldCatalog
{
	/** @var array<string, list<string>> Element type => names */
	private array $names = [];

	public function __construct(private readonly Connection $connection, private readonly Logger $logger)
	{
	}

	/**
	 * @throws DatabaseException When llx_extrafields cannot be read
	 */
	public function namesFor(string $elementType): array
	{
		if (isset($this->names[$elementType])) {
			return $this->names[$elementType];
		}
		$currentEntity = (int) ($GLOBALS['conf']->entity ?? 1);
		$rows = $this->connection->fetchAll(
			'SELECT name, type, entity FROM @extrafields WHERE elementtype = :elementType ORDER BY pos',
			['elementType' => $elementType]
		);
		$names = [];
		foreach ($rows as $row) {
			$entity = (int) $row['entity'];
			$name = (string) $row['name'];
			if (($entity !== 0 && $entity !== $currentEntity) || $row['type'] === 'separate') {
				continue;
			}
			if (!Orm::isExtraFieldName($name)) {
				$this->logger->warning(__METHOD__.': extra field "'.$name.'" of '.$elementType.' skipped: not an unquoted identifier, or a technical or secret column');
				continue;
			}
			$names[$name] = true;
		}

		return $this->names[$elementType] = array_keys($names);
	}
}
