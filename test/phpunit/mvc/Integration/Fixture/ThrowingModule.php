<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Fixture;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
use Dolibarr\Core\Module\ModuleInterface;

final class ThrowingModule implements ModuleInterface
{
	/**
	 * @param array<string, string> $templateDirs
	 */
	public function __construct(private readonly array $templateDirs = [])
	{
	}

	public function name(): string
	{
		return 'throwing';
	}

	/**
	 * @return list<class-string>
	 */
	public function controllers(): array
	{
		return [ThrowingController::class];
	}

	/**
	 * @return list<EntityDefinition>
	 */
	public function entities(): array
	{
		return [];
	}

	public function services(Container $c): void
	{
		$c->set(ThrowingController::class, static fn (): ThrowingController => new ThrowingController());
	}

	public function listeners(EventDispatcher $d): void
	{
	}

	/**
	 * @return array<string, string>
	 */
	public function templateDirs(): array
	{
		return $this->templateDirs;
	}

	/**
	 * @return list<LegacyRedirect>
	 */
	public function legacyRedirects(): array
	{
		return [];
	}
}
