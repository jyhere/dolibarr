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
	 * @param list<LegacyRedirect> $rules
	 */
	public function __construct(private readonly array $rules)
	{
	}

	public function name(): string
	{
		return 'stub';
	}

	public function controllers(): array
	{
		return [];
	}

	public function services(Container $c): void
	{
	}

	public function listeners(EventDispatcher $d): void
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
