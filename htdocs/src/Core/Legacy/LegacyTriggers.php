<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

final class LegacyTriggers
{
	/** @var list<string> */
	private array $errors = [];

	public function __construct(private readonly LegacyContext $ctx)
	{
	}

	/**
	 * @return int >= 0 on success, < 0 on errors
	 */
	public function run(string $action, \CommonObject $object, \User $actor): int
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/interfaces.class.php';

		$interfaces = new \Interfaces($this->ctx->db);
		$result = (int) $interfaces->run_triggers($action, $object, $actor, $this->ctx->langs, $this->ctx->conf);

		$this->errors = [];
		foreach ($interfaces->errors as $e) {
			$this->errors[] = (string) $e;
		}

		return $result;
	}

	/**
	 * @return list<string>
	 */
	public function errors(): array
	{
		return $this->errors;
	}
}
