<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Fixture;

use Dolibarr\Core\Routing\Route;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\View\ViewModel;

final class ThrowingController
{
	public const SECRET = 'SELECT secret FROM llx_user';

	#[Route('/fixture/boom', name: 'fixture_boom', public: true)]
	public function boom(): ViewModel
	{
		throw new \RuntimeException(self::SECRET);
	}

	#[Route('/fixture/denied', name: 'fixture_denied', public: true)]
	public function denied(): ViewModel
	{
		throw new AccessDeniedException('NotEnoughPermissions');
	}

	#[Route('/fixture/get-only', name: 'fixture_get_only', public: true)]
	public function getOnly(): ViewModel
	{
		return new ViewModel('core:error404');
	}
}
