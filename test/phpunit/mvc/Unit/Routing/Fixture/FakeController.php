<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing\Fixture;

use Dolibarr\Core\Routing\Route;

final class FakeController
{
	#[Route('/user/{id}', name: 'user_show', requirements: ['id' => '\d+'])]
	public function show(): void
	{
	}

	#[Route('/login', methods: ['GET', 'POST'], name: 'login', public: true)]
	public function login(): void
	{
	}

	#[Route('/user', name: 'user_list')]
	public function list(): void
	{
	}

	#[Route('/bye', name: 'bye', constants: ['EVEN_IF_ONLY_LOGIN_ALLOWED'])]
	public function bye(): void
	{
	}
}
