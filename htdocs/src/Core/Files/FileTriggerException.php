<?php

declare(strict_types=1);

namespace Dolibarr\Core\Files;

/**
 * A legacy ECMFILES_* trigger failed: the write of the file index is rolled back and the messages are shown.
 */
final class FileTriggerException extends \RuntimeException
{
	/**
	 * @param list<string> $errors The trigger messages
	 */
	public function __construct(public readonly array $errors)
	{
		parent::__construct('Error in some triggers: '.implode(', ', $errors));
	}
}
