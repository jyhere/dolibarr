<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing\Fixture;

use Dolibarr\Core\Routing\Route;

final class DuplicateNameController
{
	#[Route('/first', name: 'same')]
	public function first(): void
	{
	}

	#[Route('/second', name: 'same')]
	public function second(): void
	{
	}
}
