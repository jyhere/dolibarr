<?php

declare(strict_types=1);

namespace Dolibarr\Module\Documents\Service;

/**
 * What an action on attached files reports: its messages, in order, typed as setEventMessages() types them, and
 * whether it went without error. A text is trimmed and an empty one ignored, as setEventMessage() does.
 */
final class ActionResult
{
	/** @var list<array{type: 'mesgs'|'warnings'|'errors', text: string}> */
	public array $messages = [];

	/** False once an error message is added */
	public bool $ok = true;

	/**
	 * @throws \InvalidArgumentException A type other than mesgs, warnings or errors
	 */
	public function add(string $type, string $text): void
	{
		if ($type !== 'mesgs' && $type !== 'warnings' && $type !== 'errors') {
			throw new \InvalidArgumentException('Unknown message type '.$type);
		}
		$text = trim($text);
		if ($text === '') {
			return;
		}
		$this->messages[] = ['type' => $type, 'text' => $text];
		if ($type === 'errors') {
			$this->ok = false;
		}
	}

	/**
	 * setEventMessages($message, $messages, $type): the message, unless it is one of the list, then the list.
	 *
	 * @param list<string> $messages
	 * @throws \InvalidArgumentException A type other than mesgs, warnings or errors
	 */
	public function addAll(string $type, string $message, array $messages): void
	{
		if ($message !== '' && !in_array($message, $messages, true)) {
			$this->add($type, $message);
		}
		foreach ($messages as $text) {
			$this->add($type, $text);
		}
	}
}
