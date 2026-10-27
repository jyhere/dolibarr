<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Database\Fixture;

enum BinderStatus: string
{
	case Enabled = 'enabled';
	case Disabled = 'disabled';
}
