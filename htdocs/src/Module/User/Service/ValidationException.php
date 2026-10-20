<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Service;

/**
 * A write was refused; the messages are ready to display (already translated).
 */
final class ValidationException extends \RuntimeException
{
	/**
	 * @param list<string> $messages
	 */
	public function __construct(public readonly array $messages)
	{
		parent::__construct(implode("\n", $messages));
	}
}
