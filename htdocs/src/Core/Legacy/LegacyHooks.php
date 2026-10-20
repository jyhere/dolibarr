<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

final class LegacyHooks
{
	public function __construct(private readonly \HookManager $hm)
	{
	}

	/**
	 * @param list<string> $contexts
	 */
	public function init(array $contexts): void
	{
		$this->hm->initHooks($contexts);
	}

	/**
	 * Run executeHooks(). What the hooks echo is captured into HookResult::$output, never sent:
	 * the caller places it where the legacy page would have printed it.
	 *
	 * @param array<string,mixed> $params
	 */
	public function execute(string $name, array $params, ?object &$object, string &$action): HookResult
	{
		ob_start();
		try {
			$code = (int) $this->hm->executeHooks($name, $params, $object, $action);
		} finally {
			$output = (string) ob_get_clean();
		}

		// Same messages, same order as setEventMessages($hookmanager->error, $hookmanager->errors)
		$errors = [];
		foreach ($this->hm->errors as $e) {
			$errors[] = (string) $e;
		}
		$error = (string) $this->hm->error;
		if ($error !== '' && !in_array($error, $errors, true)) {
			array_unshift($errors, $error);
		}

		return new HookResult($code, (string) $this->hm->resPrint, $this->hm->resArray, $errors, $output);
	}

	/**
	 * @param list<string> $contexts
	 */
	public function hasListenersFor(array $contexts): bool
	{
		foreach ($contexts as $ctx) {
			if (!empty($this->hm->hooks[$ctx])) {
				return true;
			}
		}

		return false;
	}
}
