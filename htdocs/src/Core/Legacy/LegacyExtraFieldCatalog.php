<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\Config;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Logger;
use Dolibarr\Core\Orm\ExtraFieldCatalog;
use Dolibarr\Core\Orm\Orm;

/**
 * The extra fields of llx_extrafields, filtered as ExtraFields::fetch_name_optionals_label() does: the definitions
 * of the element type for entity 0 or the current entity, by position; a separator ('separate') has no column and
 * is left out. A name that cannot be an extra field column (Orm::isExtraFieldName(): an unquoted identifier, so
 * no '-' although the legacy accepts it, at most 59 characters, neither technical nor secret) is left out with a
 * warning in the log: the queries never fail on it.
 *
 * With MAIN_EXTRAFIELDS_DISABLED, the catalog is disabled and empty, as the legacy fetch_optionals(),
 * insertExtraFields() and deleteExtraFields() do nothing.
 *
 * Names are kept exactly as stored, as the legacy creates the column (PostgreSQL folds it to lower case; the ORM
 * maps the column back to the stored name). One statement per element type, cached by element type for the
 * request (one instance per request: the container's Orm); the current entity and MAIN_EXTRAFIELDS_DISABLED are
 * read once, by the first call.
 */
final class LegacyExtraFieldCatalog implements ExtraFieldCatalog
{
	/** @var array<string, list<string>> Element type => names */
	private array $names = [];

	private ?int $currentEntity = null;

	private ?bool $enabled = null;

	public function __construct(
		private readonly Connection $connection,
		private readonly Config $config,
		private readonly Logger $logger
	) {
	}

	public function enabled(): bool
	{
		if ($this->enabled === null) {
			$disabled = $this->config->string('MAIN_EXTRAFIELDS_DISABLED');
			// Legacy: getDolGlobalString(...) tested as a PHP boolean ('' and '0' are false)
			$this->enabled = $disabled === '' || $disabled === '0';
		}

		return $this->enabled;
	}

	/**
	 * @throws DatabaseException When llx_extrafields cannot be read
	 */
	public function namesFor(string $elementType): array
	{
		if (isset($this->names[$elementType])) {
			return $this->names[$elementType];
		}
		if (!$this->enabled()) {
			return $this->names[$elementType] = [];
		}
		$this->currentEntity ??= $this->config->entity();
		$rows = $this->connection->fetchAll(
			'SELECT name, type, entity FROM @extrafields WHERE elementtype = :elementType ORDER BY pos',
			['elementType' => $elementType]
		);
		$names = [];
		foreach ($rows as $row) {
			$entity = (int) $row['entity'];
			$name = (string) $row['name'];
			if (($entity !== 0 && $entity !== $this->currentEntity) || $row['type'] === 'separate') {
				continue;
			}
			if (!Orm::isExtraFieldName($name)) {
				$this->logger->warning(__METHOD__.': extra field "'.$name.'" of '.$elementType.' skipped: not an unquoted identifier, longer than '.Orm::EXTRA_FIELD_NAME_MAX_LENGTH.' characters, or a technical or secret column');
				continue;
			}
			$names[$name] = true;
		}

		return $this->names[$elementType] = array_keys($names);
	}
}
