<?php

declare(strict_types=1);

namespace Dolibarr\Module\Notes\Listener;

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
		private readonly Logger $log
	) {
	}

	public function register(EventDispatcher $d): void
	{
		$d->addListener(NoteUpdated::class, $this->onNoteUpdated(...));
	}

	/**
	 * @throws NoteTriggerException When a trigger fails, so that the write is rolled back
	 * @throws \LogicException      Without a logged legacy user, or for an element without legacy loader
	 */
	public function onNoteUpdated(NoteUpdated $e): void
	{
		if ($e->legacyTriggerFired) {
			return;
		}
		$actor = $this->ctx->user;
		if ($actor === null) {
			throw new \LogicException('A note trigger needs the logged legacy user');
		}
		$object = $this->loader->byElement($e->element, $e->id);
		if ($object === null) {
			$this->log->warning('NoteTriggerBridge: '.$e->element.' '.$e->id.' not found, MODIFY trigger skipped');

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
