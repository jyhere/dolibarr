<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Fixture;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
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

	/**
	 * @return list<EntityDefinition>
	 */
	public function entities(): array
	{
		return [];
	}

	public function services(Container $container): void
	{
	}

	public function listeners(EventDispatcher $dispatcher): void
	{
	}

	/**
	 * @return array<string, string>
	 */
	public function templateDirs(): array
	{
		return [];
	}

	/**
	 * @return list<LegacyRedirect>
	 */
	public function legacyRedirects(): array
	{
		return [];
	}
}
