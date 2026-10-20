<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Event;

use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Tests\Unit\Event\Fixture\BaseEvent;
use Dolibarr\Tests\Unit\Event\Fixture\ChildEvent;
use Dolibarr\Tests\Unit\Event\Fixture\MarkedEvent;
use Dolibarr\Tests\Unit\Event\Fixture\MarkerInterface;
use Dolibarr\Tests\Unit\Event\Fixture\StoppableFixtureEvent;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
	public function testListenersRunByPriorityThenInsertionOrder(): void
	{
		$calls = [];
		$dispatcher = new EventDispatcher();
		$dispatcher->addListener(BaseEvent::class, static function () use (&$calls): void {
			$calls[] = 'low';
		}, -5);
		$dispatcher->addListener(BaseEvent::class, static function () use (&$calls): void {
			$calls[] = 'first0';
		});
		$dispatcher->addListener(BaseEvent::class, static function () use (&$calls): void {
			$calls[] = 'high';
		}, 10);
		$dispatcher->addListener(BaseEvent::class, static function () use (&$calls): void {
			$calls[] = 'second0';
		});

		$dispatcher->dispatch(new BaseEvent());

		$this->assertSame(['high', 'first0', 'second0', 'low'], $calls);
	}

	public function testParentClassAndInterfaceListenersReceiveEvent(): void
	{
		$calls = [];
		$dispatcher = new EventDispatcher();
		$dispatcher->addListener(BaseEvent::class, static function () use (&$calls): void {
			$calls[] = 'parent';
		});
		$dispatcher->addListener(ChildEvent::class, static function () use (&$calls): void {
			$calls[] = 'child';
		});
		$dispatcher->addListener(MarkerInterface::class, static function () use (&$calls): void {
			$calls[] = 'interface';
		});

		$dispatcher->dispatch(new ChildEvent());
		$this->assertSame(['child', 'parent'], $calls);

		$calls = [];
		$dispatcher->dispatch(new MarkedEvent());
		$this->assertSame(['interface'], $calls);
		$this->assertTrue($dispatcher->hasListeners(MarkerInterface::class));
		$this->assertFalse($dispatcher->hasListeners(\stdClass::class));
	}

	public function testStoppableEventHaltsPropagation(): void
	{
		$calls = [];
		$dispatcher = new EventDispatcher();
		$dispatcher->addListener(StoppableFixtureEvent::class, static function (StoppableFixtureEvent $e) use (&$calls): void {
			$calls[] = 'one';
			$e->stopped = true;
		}, 1);
		$dispatcher->addListener(StoppableFixtureEvent::class, static function () use (&$calls): void {
			$calls[] = 'two';
		});

		$dispatcher->dispatch(new StoppableFixtureEvent());

		$this->assertSame(['one'], $calls);
	}

	public function testDispatchReturnsSameInstance(): void
	{
		$dispatcher = new EventDispatcher();
		$event = new BaseEvent();

		$this->assertSame($event, $dispatcher->dispatch($event));
	}
}
