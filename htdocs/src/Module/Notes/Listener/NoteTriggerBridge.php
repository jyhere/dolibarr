<?php

declare(strict_types=1);

namespace Dolibarr\Module\Notes\Listener;

use Dolibarr\Core\Entity\EntityRegistry;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Logger;
use Dolibarr\Module\Notes\Event\NoteUpdated;

/**
 * Runs the legacy trigger of CommonObject::update_note() (l.4220-4246) for a note written by the component.
 */
final class NoteTriggerBridge
{
	public function __construct(
		private readonly LegacyTriggers $triggers,
		private readonly LegacyContext $ctx,
		private readonly LegacyObjectLoader $loader,
		private readonly Logger $log,
		private readonly EntityRegistry $registry
	) {
	}

	public function register(EventDispatcher $dispatcher): void
	{
		$dispatcher->addListener(NoteUpdated::class, $this->onNoteUpdated(...));
	}

	/**
	 * @throws NoteTriggerException When a trigger fails, so that the write is rolled back
	 * The legacy object is loaded from the EntityDefinition of the event's element.
	 *
	 * @throws \LogicException      Without a logged legacy user, or for an element no module declares
	 */
	public function onNoteUpdated(NoteUpdated $event): void
	{
		if ($event->legacyTriggerFired) {
			return;
		}
		$actor = $this->ctx->user;
		if ($actor === null) {
			throw new \LogicException('A note trigger needs the logged legacy user');
		}
		$definition = $this->registry->findByElement($event->element);
		if ($definition === null) {
			throw new \LogicException('No entity type declared for the element '.$event->element);
		}
		$object = $this->loader->byElement($definition, $event->id);
		if ($object === null) {
			$this->log->warning('NoteTriggerBridge: '.$event->element.' '.$event->id.' not found, MODIFY trigger skipped');

			return;
		}

		if ($this->triggers->run(self::triggerName($object), $object, $actor) < 0) {
			throw new NoteTriggerException($this->triggers->errors());
		}
	}

	/**
	 * update_note() l.4222-4242.
	 */
	private static function triggerName(\CommonObject $object): string
	{
		return match ($object->element) {
			'societe' => 'COMPANY_MODIFY',
			'facture' => 'BILL_MODIFY',
			'invoice_supplier' => 'BILL_SUPPLIER_MODIFY',
			'facturerec' => 'BILLREC_MODIFIY',
			'expensereport' => 'EXPENSE_REPORT_MODIFY',
			default => (!empty($object->TRIGGER_PREFIX) ? $object->TRIGGER_PREFIX : strtoupper($object->element)).'_MODIFY',
		};
	}
}
