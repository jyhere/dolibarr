<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Fixture;

/**
 * Hook actions class whose methods echo, set resprints and return what the script says.
 * Register it with register() on a HookManager: executeHooks() iterates hooksSorted.
 */
final class ScriptedHooks
{
	/** @var array<mixed> */
	public array $results = [];

	public ?string $resprints = null;

	public string $error = '';

	/** @var list<string> */
	public array $errors = [];

	/** @var list<string> */
	public array $warnings = [];

	/** @var list<string> */
	public array $calls = [];

	/** @var array<string, array<string,mixed>> */
	public array $parameters = [];

	/** @var array<string, string> The $action each hook received, by hook name */
	public array $actions = [];

	/**
	 * @param array<string, array{echo?: string, print?: string, code?: int, errors?: list<string>, error?: string}> $script
	 */
	public function __construct(private readonly array $script)
	{
	}

	public static function register(\HookManager $hm, string $context, object $hooks): void
	{
		$hm->hooks[$context]['mvctest'] = $hooks;
		$hm->hooksSorted[$context]['50:mvctest'] = $hooks;
	}

	/**
	 * @param array<string,mixed> $parameters
	 */
	public function doActions(array $parameters, mixed &$object, string &$action, \HookManager $hm): int
	{
		$this->actions[__FUNCTION__] = $action;

		return $this->run(__FUNCTION__, $parameters);
	}

	/**
	 * @param array<string,mixed> $parameters
	 */
	public function formObjectOptions(array $parameters, mixed &$object, string &$action, \HookManager $hm): int
	{
		return $this->run(__FUNCTION__, $parameters);
	}

	/**
	 * @param array<string,mixed> $parameters
	 */
	public function addMoreObjectBlock(array $parameters, mixed &$object, string &$action, \HookManager $hm): int
	{
		return $this->run(__FUNCTION__, $parameters);
	}

	/**
	 * @param array<string,mixed> $parameters
	 */
	public function addMoreActionsButtons(array $parameters, mixed &$object, string &$action, \HookManager $hm): int
	{
		return $this->run(__FUNCTION__, $parameters);
	}

	/**
	 * @param array<string,mixed> $parameters
	 */
	public function insertExtraHeader(array $parameters, mixed &$object, string &$action, \HookManager $hm): int
	{
		return $this->run(__FUNCTION__, $parameters);
	}

	/**
	 * @param array<string,mixed> $parameters
	 */
	public function insertExtraFooter(array $parameters, mixed &$object, string &$action, \HookManager $hm): int
	{
		return $this->run(__FUNCTION__, $parameters);
	}

	/**
	 * @param array<string,mixed> $parameters
	 */
	private function run(string $method, array $parameters): int
	{
		$this->calls[] = $method;
		$this->parameters[$method] = $parameters;
		$step = $this->script[$method] ?? [];
		echo $step['echo'] ?? '';
		if (isset($step['print'])) {
			$this->resprints = $step['print'];
		}
		$this->errors = $step['errors'] ?? [];
		$this->error = $step['error'] ?? '';

		return $step['code'] ?? 0;
	}
}
