<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\FlashBag;
use PHPUnit\Framework\TestCase;

final class FlashBagTest extends TestCase
{
	public function testFlashBagWritesDolEvents(): void
	{
		$_SESSION = [];
		(new FlashBag())->error('x');
		self::assertSame(['x'], $_SESSION['dol_events']['errors']);
	}
}
