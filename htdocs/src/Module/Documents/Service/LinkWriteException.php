<?php

declare(strict_types=1);

namespace Dolibarr\Module\Documents\Service;

/**
 * A link could not be written: the write is rolled back. The message is the one \Link keeps in $error (the
 * duplicate of uk_links, a missing URL, a trigger failure), $errors the messages of the failing LINK_* trigger
 * (\Link::$errors), which the documents page shows.
 */
final class LinkWriteException extends \RuntimeException
{
	/**
	 * @param list<string> $errors
	 */
	public function __construct(string $message, public readonly array $errors = [], ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}
