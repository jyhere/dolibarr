<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm;

use Dolibarr\Core\Legacy\LegacyEntityScope;
use PHPUnit\Framework\TestCase;

/**
 * LegacyEntityScope: the integers of getEntity($element) (no multicompany in the test install: entity 1, plus 0
 * for the elements getEntity() shares with entity 0).
 */
final class LegacyEntityScopeTest extends TestCase
{
	public function testIntegersOfGetEntity(): void
	{
		$scope = new LegacyEntityScope();

		self::assertSame(array_map('intval', explode(',', getEntity('societe'))), $scope->entitiesFor('societe'));
		self::assertSame([1], $scope->entitiesFor('societe'));
		self::assertSame([0, 1], $scope->entitiesFor('user'));
	}
}
