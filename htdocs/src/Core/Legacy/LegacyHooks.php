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
	 * @param array<string,mixed> $params
	 */
	public function execute(string $name, array $params, ?object &$object, string &$action): HookResult
	{
		$code = (int) $this->hm->executeHooks($name, $params, $object, $action);

		$errors = [];
		foreach ($this->hm->errors as $e) {
			$errors[] = (string) $e;
		}
		if ($this->hm->error !== '' && $errors === []) {
			$errors[] = (string) $this->hm->error;
		}

		return new HookResult($code, (string) $this->hm->resPrint, $this->hm->resArray, $errors);
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
