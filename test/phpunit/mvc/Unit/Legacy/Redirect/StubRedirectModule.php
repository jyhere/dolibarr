<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Legacy\Redirect;

use Dolibarr\Core\Container\Container;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
use Dolibarr\Core\Module\ModuleInterface;

final class StubRedirectModule implements ModuleInterface
{
	/**
	 * @param list<LegacyRedirect>  $rules
	 * @param list<class-string>    $controllers
	 */
	public function __construct(private readonly array $rules, private readonly array $controllers = [])
	{
	}

	public function name(): string
	{
		return 'stub';
	}

	public function controllers(): array
	{
		return $this->controllers;
	}

	public function services(Container $container): void
	{
	}

	public function listeners(EventDispatcher $dispatcher): void
	{
	}

	public function entities(): array
	{
		return [];
	}

	public function templateDirs(): array
	{
		return [];
	}

	public function legacyRedirects(): array
	{
		return $this->rules;
	}
}
