<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Fixture;

use Dolibarr\Core\Routing\Route;

final class FixtureController
{
	#[Route('/fixture/public', name: 'fixture_public', public: true)]
	public function publicPage(): void
	{
	}

	#[Route('/fixture/{id}', name: 'fixture_show', requirements: ['id' => '\d+'])]
	public function show(int $id): void
	{
	}
}
