<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Fixture;

/**
 * Temporary $conf->global overrides, restored by restoreConf() (call it in tearDown).
 */
trait OverridesLegacyConf
{
	/** @var array<string, mixed> */
	private array $savedConf = [];

	private function setConf(string $key, int|string $value): void
	{
		$global = $GLOBALS['conf']->global;
		if (!array_key_exists($key, $this->savedConf)) {
			$this->savedConf[$key] = $global->$key ?? null;
		}
		$global->$key = $value;
	}

	/**
	 * The agenda trigger sets the undeclared property trackid on \User (a PHP 8.2+ deprecation
	 * in legacy code, interface_50_modAgenda_ActionsAuto l.1513-1516): the automatic agenda
	 * events of users are switched off in the tests that write users.
	 */
	private function disableUserAgendaEvents(): void
	{
		foreach (['USER_CREATE', 'USER_MODIFY', 'USER_DELETE', 'USER_NEW_PASSWORD', 'USER_ENABLEDISABLE', 'USER_SENTBYMAIL'] as $action) {
			$this->setConf('MAIN_AGENDA_ACTIONAUTO_'.$action, 0);
		}
	}

	private function restoreConf(): void
	{
		$global = $GLOBALS['conf']->global;
		foreach ($this->savedConf as $key => $value) {
			if ($value === null) {
				unset($global->$key);
			} else {
				$global->$key = $value;
			}
		}
		$this->savedConf = [];
	}
}
