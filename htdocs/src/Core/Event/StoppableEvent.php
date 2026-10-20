<?php

declare(strict_types=1);

namespace Dolibarr\Core\Event;

/**
 * An event that can halt its own propagation to later listeners.
 */
interface StoppableEvent
{
	public function isPropagationStopped(): bool;
}
