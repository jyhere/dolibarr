<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Event\Fixture;

use Dolibarr\Core\Event\StoppableEvent;

final class StoppableFixtureEvent implements StoppableEvent
{
	public bool $stopped = false;

	public function isPropagationStopped(): bool
	{
		return $this->stopped;
	}
}
