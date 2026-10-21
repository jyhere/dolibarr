<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing\Fixture;

use Dolibarr\Core\Routing\Route;

final class UnsafeConstantController
{
	#[Route('/unsafe', name: 'unsafe', constants: ['NOCSRFCHECK'])]
	public function unsafe(): void
	{
	}
}
