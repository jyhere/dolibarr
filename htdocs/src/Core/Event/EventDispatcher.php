<?php

declare(strict_types=1);

namespace Dolibarr\Core\Event;

/**
 * Dispatches typed events to listeners registered by event class or interface.
 *
 * Listeners are resolved for the event's exact class, then each parent class,
 * then each interface. A listener registered for several of those types
 * receives the event once per matching registration.
 */
final class EventDispatcher
{
	/** @var array<string,list<array{priority:int,sequence:int,listener:callable}>> */
	private array $listeners = [];

	private int $sequence = 0;

	public function addListener(string $eventClass, callable $listener, int $priority = 0): void
	{
		$this->listeners[$eventClass][] = [
			'priority' => $priority,
			'sequence' => $this->sequence++,
			'listener' => $listener,
		];
	}

	/**
	 * @template T of object
	 * @param T $event
	 * @return T
	 */
	public function dispatch(object $event): object
	{
		$types = array_merge(
			[get_class($event)],
			array_values(class_parents($event) ?: []),
			array_values(class_implements($event) ?: [])
		);

		foreach ($types as $type) {
			$entries = $this->listeners[$type] ?? [];
			usort($entries, static function (array $a, array $b): int {
				return [$b['priority'], $a['sequence']] <=> [$a['priority'], $b['sequence']];
			});
			foreach ($entries as $entry) {
				$entry['listener']($event);
				if ($event instanceof StoppableEvent && $event->isPropagationStopped()) {
					return $event;
				}
			}
		}

		return $event;
	}

	public function hasListeners(string $eventClass): bool
	{
		return !empty($this->listeners[$eventClass]);
	}
}
