<?php

declare(strict_types=1);

namespace Dolibarr\Module\Notes\Listener;

/**
 * The legacy trigger of a note update failed: the write must be rolled back and the messages shown.
 */
final class NoteTriggerException extends \RuntimeException
{
	/**
	 * @param list<string> $errors The trigger messages
	 */
	public function __construct(public readonly array $errors)
	{
		parent::__construct('Error in some triggers: '.implode(', ', $errors));
	}
}
