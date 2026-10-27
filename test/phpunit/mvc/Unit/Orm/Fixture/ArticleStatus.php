<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Orm\Fixture;

enum ArticleStatus: int
{
	case Draft = 0;
	case Published = 1;
}
