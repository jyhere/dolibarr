<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing\Fixture;

use Dolibarr\Core\Routing\Route;

final class MethodsController
{
	#[Route('/lower', methods: ['get', 'Post'], name: 'lower')]
	public function lower(): void
	{
	}

	#[Route('/probe', methods: ['HEAD'], name: 'probe_head')]
	public function probeHead(): void
	{
	}

	#[Route('/probe', methods: ['GET'], name: 'probe_get')]
	public function probeGet(): void
	{
	}
}
