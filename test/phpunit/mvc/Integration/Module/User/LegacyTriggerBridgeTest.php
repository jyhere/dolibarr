<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\User;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Legacy\LegacyTriggers;
use Dolibarr\Core\Logger;
use Dolibarr\Module\User\Event\UserLoggedIn;
use Dolibarr\Module\User\Listener\LegacyTriggerBridge;
use PHPUnit\Framework\TestCase;

final class LegacyTriggerBridgeTest extends TestCase
{
	public function testLoggedInEventCarriesIdAndBridgeLoadsLegacyUser(): void
	{
		$ctx = LegacyContext::fromGlobals();
		$connection = new Connection($ctx->db);
		$previous = $ctx->conf->global->MAIN_LOGEVENTS_USER_LOGIN ?? null;
		$ctx->conf->global->MAIN_LOGEVENTS_USER_LOGIN = 1;
		$before = $this->countLoginEvents($connection);

		$dispatcher = new EventDispatcher();
		(new LegacyTriggerBridge(new LegacyTriggers($ctx), $ctx, new LegacyObjectLoader($connection), new Logger()))->register($dispatcher);
		$spied = [];
		$dispatcher->addListener(UserLoggedIn::class, static function (UserLoggedIn $event) use (&$spied): void {
			$spied[] = $event;
		});

		try {
			$dispatcher->dispatch(new UserLoggedIn(1, 'admin', 'dolibarr', 'TZ=;authmode=dolibarr'));
		} finally {
			if ($previous === null) {
				unset($ctx->conf->global->MAIN_LOGEVENTS_USER_LOGIN);
			} else {
				$ctx->conf->global->MAIN_LOGEVENTS_USER_LOGIN = $previous;
			}
		}

		self::assertCount(1, $spied);
		foreach ((new \ReflectionObject($spied[0]))->getProperties() as $property) {
			self::assertNotInstanceOf(\User::class, $property->getValue($spied[0]), 'The event must carry ids, not the legacy user');
		}
		self::assertSame(1, $spied[0]->userId);
		self::assertSame($before + 1, $this->countLoginEvents($connection));
	}

	public function testUnknownUserSkipsTheTrigger(): void
	{
		$ctx = LegacyContext::fromGlobals();
		$connection = new Connection($ctx->db);
		$dispatcher = new EventDispatcher();
		(new LegacyTriggerBridge(new LegacyTriggers($ctx), $ctx, new LegacyObjectLoader($connection), new Logger()))->register($dispatcher);

		$dispatcher->dispatch(new UserLoggedIn(999999, 'ghost', 'dolibarr', ''));

		self::addToAssertionCount(1);
	}

	private function countLoginEvents(Connection $connection): int
	{
		$row = $connection->fetchOne("SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."events WHERE type = 'USER_LOGIN' AND fk_user = 1");
		self::assertNotNull($row);

		return (int) $row->nb;
	}
}
