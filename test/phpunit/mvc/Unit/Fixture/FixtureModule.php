<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Fixture;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Module\ModuleInterface;

final class FixtureModule implements ModuleInterface
{
	public function name(): string
	{
		return 'fixture';
	}

	/**
	 * @return list<class-string>
	 */
	public function controllers(): array
	{
		return [FixtureController::class];
	}

	public function services(Container $c): void
	{
	}

	public function listeners(EventDispatcher $d): void
	{
	}

	/**
	 * @return array<string, string>
	 */
	public function templateDirs(): array
	{
		return [];
	}
}
