<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Routing\Fixture;

use Dolibarr\Core\Http\Request;

final class ArgumentsController
{
	public function typed(Request $request, int $id, float $ratio, bool $flag, string $slug): void
	{
	}

	public function defaults(int $id, string $mode = 'view', ?int $page = null, ?string $filter = null): void
	{
	}

	public function unresolvable(int $missing): void
	{
	}
}
