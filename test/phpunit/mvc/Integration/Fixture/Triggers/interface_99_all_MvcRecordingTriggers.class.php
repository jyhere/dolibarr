<?php

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

use Dolibarr\Tests\Integration\Fixture\RecordingTriggers;

/**
 * Trigger file of the tests, loaded by Interfaces::run_triggers() once RecordingTriggers::install() ran.
 * It records the state of the \EcmFiles it receives and fails when RecordingTriggers::failOn() says so.
 */
class InterfaceMvcRecordingTriggers extends DolibarrTriggers
{
	public function __construct(DoliDB $db)
	{
		parent::__construct($db);
		$this->family = 'test';
		$this->description = 'Records the ECMFILES triggers';
		$this->version = self::VERSIONS['dev'];
	}

	/**
	 * @param string    $action Event action code
	 * @param \CommonObject $object The object of the event
	 * @param User      $user   Actor
	 * @param Translate $langs  Translations
	 * @param Conf      $conf   Configuration
	 * @return int
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (strpos($action, 'ECMFILES_') !== 0) {
			return 0;
		}
		$properties = [];
		foreach (['id', 'entity', 'filepath', 'filename', 'label', 'share', 'src_object_type', 'src_object_id', 'fullpath_orig', 'gen_or_uploaded', 'description', 'keywords', 'fk_user_c', 'fk_user_m'] as $property) {
			$properties[$property] = $object->$property ?? null;
		}
		RecordingTriggers::record([
			'action' => $action,
			'class' => get_class($object),
			'actor' => $user->id,
			'object' => $properties,
		]);
		$failure = RecordingTriggers::failureOf($action);
		if ($failure !== null) {
			$this->errors[] = $failure;

			return -1;
		}

		return 1;
	}
}
