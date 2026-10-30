<?php

declare(strict_types=1);

namespace Dolibarr\Module\Documents\Service;

use Dolibarr\Core\Config;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Database\DatabaseException;
use Dolibarr\Core\Files\ExternalLink;
use Dolibarr\Core\Files\ExternalLinkRepository;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyFileObjects;
use Dolibarr\Core\Legacy\LegacyTriggers;

/**
 * Writes the links of an object (llx_links) as \Link::create(), update() and delete() do, with the LINK_CREATE,
 * LINK_MODIFY and LINK_DELETE triggers. Each write and its trigger share one transaction: a failure rolls the
 * write back and raises LinkWriteException.
 */
final class LinkWriter
{
	private const DUPLICATE = 'DB_ERROR_RECORD_ALREADY_EXISTS';

	public function __construct(
		private readonly ExternalLinkRepository $links,
		private readonly Connection $connection,
		private readonly LegacyTriggers $triggers,
		private readonly LegacyFileObjects $objects,
		private readonly Config $config,
		private readonly LegacyContext $context
	) {
	}

	/**
	 * \Link::create() (l.105-170) for the object of the target (ruling R3: never an object type or id read from the
	 * request): the label is the base name of the URL when empty, the entity the current one.
	 *
	 * @throws LinkWriteException An empty URL, a duplicate label for the object (uk_links), a database error or a
	 *                             failing trigger: nothing is written
	 * @throws \LogicException     Without a logged user, or if the trigger object cannot be loaded
	 */
	public function create(DocumentsTarget $target, string $url, string $label): ExternalLink
	{
		if (self::isEmpty($label)) {
			$label = trim(basename($url));
		}
		$url = trim($url);
		if ($url === '') {
			throw new LinkWriteException('ErrorFieldRequired');
		}
		$link = new ExternalLink(
			null,
			$this->config->entity(),
			self::now(),
			$url,
			$label,
			(string) $target->legacyObject->element,
			(int) $target->legacyObject->id
		);

		return $this->write(function () use ($link): ExternalLink {
			$saved = $this->links->save($link);
			$this->fireTrigger('LINK_CREATE', (int) $saved->id);

			return $saved;
		});
	}

	/**
	 * \Link::update() (l.192-260): the URL cleaned by clean_url(), the label the base name of the URL when empty,
	 * the date set to now, the entity to the current one, and the share key drawn again at each save with the
	 * sharing on (actions_linkedfiles.inc.php l.218-224), removed with it off.
	 *
	 * @throws LinkWriteException An empty URL, a duplicate label, a database error or a failing trigger: the row
	 *                             is unchanged
	 * @throws \LogicException     Without a logged user, or if the trigger object cannot be loaded
	 */
	public function update(ExternalLink $link, string $url, string $label, bool $share): ExternalLink
	{
		if ($url === '') {
			throw new LinkWriteException('ErrorFieldRequired');
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		$cleanUrl = clean_url($url, 1);
		$shareKey = null;
		if ($share) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';
			$shareKey = getRandomPassword(true);
		}
		$changes = [
			'entity' => $this->config->entity(),
			'linkedAt' => self::now(),
			'url' => $cleanUrl,
			'label' => $this->cleanedLabel($url, $label),
			'share' => $shareKey,
		];

		return $this->write(function () use ($link, $changes): ExternalLink {
			$saved = $this->links->save($this->links->modify($link, $changes));
			$this->fireTrigger('LINK_MODIFY', (int) $saved->id);

			return $saved;
		});
	}

	/**
	 * \Link::delete() (l.428-450): LINK_DELETE before the deletion of the row and of its extra fields.
	 *
	 * @throws LinkWriteException A database error or a failing trigger: the row is kept
	 * @throws \LogicException     Without a logged user, or if the trigger object cannot be loaded
	 */
	public function delete(ExternalLink $link): void
	{
		$this->write(function () use ($link): bool {
			$this->fireTrigger('LINK_DELETE', (int) $link->id);
			$this->links->delete($link);

			return true;
		});
	}

	/**
	 * The label update() stores for this URL and label: the label trimmed, or the base name of the cleaned URL.
	 */
	public function cleanedLabel(string $url, string $label): string
	{
		if (self::isEmpty($label)) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
			$label = basename(clean_url($url, 1));
		}

		return trim($label);
	}

	/**
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 * @throws LinkWriteException
	 * @throws \LogicException
	 */
	private function write(callable $work): mixed
	{
		try {
			return $this->connection->transactional($work);
		} catch (DatabaseException $e) {
			throw new LinkWriteException($e->engineCode() === self::DUPLICATE ? 'ErrorDuplicateField' : $e->getMessage(), [], $e);
		}
	}

	/**
	 * @throws LinkWriteException
	 * @throws \LogicException
	 */
	private function fireTrigger(string $action, int $id): void
	{
		$object = $this->objects->link($id) ?? throw new \LogicException('The link '.$id.' cannot be loaded for the trigger '.$action);
		$actor = $this->context->user ?? throw new \LogicException('A trigger of the links needs the logged legacy user');
		if ($this->triggers->run($action, $object, $actor) < 0) {
			throw new LinkWriteException('Error in some triggers', $this->triggers->errors());
		}
	}

	private static function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
	}

	/**
	 * PHP empty() of a posted text.
	 */
	private static function isEmpty(string $value): bool
	{
		return $value === '' || $value === '0';
	}
}
