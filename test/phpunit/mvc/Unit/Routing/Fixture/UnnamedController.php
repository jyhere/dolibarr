<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing\Fixture;

use Dolibarr\Core\Routing\Route;

final class UnnamedController
{
	#[Route('/a.b/{slug}')]
	#[Route('/a-b', name: 'other')]
	public function list(): void
	{
	}
}
