<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

/**
 * Holds the legacy Dolibarr globals. The only place allowed to read $GLOBALS.
 */
final class LegacyContext
{
	public function __construct(
		public readonly \Conf $conf,
		public readonly \DoliDB $db,
		public readonly \Translate $langs,
		public readonly ?\User $user,
		public readonly \HookManager $hookmanager,
	) {
	}

	/**
	 * @throws \LogicException If a required global is missing
	 */
	public static function fromGlobals(): self
	{
		$conf = $GLOBALS['conf'] ?? null;
		$db = $GLOBALS['db'] ?? null;
		$langs = $GLOBALS['langs'] ?? null;
		$user = $GLOBALS['user'] ?? null;
		$hookmanager = $GLOBALS['hookmanager'] ?? null;

		if (!$conf instanceof \Conf || !$db instanceof \DoliDB || !$langs instanceof \Translate || !$hookmanager instanceof \HookManager) {
			throw new \LogicException('Dolibarr globals conf, db, langs and hookmanager must be initialized');
		}

		return new self($conf, $db, $langs, $user instanceof \User ? $user : null, $hookmanager);
	}
}
