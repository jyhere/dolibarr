<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Module\Notes;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\UserModule;
use Dolibarr\Module\User\View\UserCardChrome;
use PHPUnit\Framework\TestCase;

/**
 * The user side of the entity contract the generic tabs use: legacy page settings and legacy object loading.
 * Integration because UserCardChrome and the loader need the legacy context (\Conf, \DoliDB...).
 */
final class EntityContractTest extends TestCase
{
	public function testUserNotePageSettingsAreTheOnesOfNotePhp(): void
	{
		$ctx = LegacyContext::fromGlobals();
		$chrome = new UserCardChrome($ctx, new UrlGenerator((new RouteCompiler())->compile([UserController::class]), ''));

		$settings = $chrome->legacyPageSettings('note');

		// user/note.php l.52, l.77, l.83 ($socid of the internal admin of the tests: 0, l.71-74)
		self::assertSame(['companies', 'members', 'bills', 'users'], $settings->translationDomains);
		self::assertSame(['usercard', 'usernote', 'globalcard'], $settings->hookContexts);
		self::assertSame(['id' => 0], $settings->doActionsParams);
	}

	public function testUnknownPageThrows(): void
	{
		$chrome = new UserCardChrome(LegacyContext::fromGlobals(), new UrlGenerator([], ''));

		$this->expectException(\InvalidArgumentException::class);
		$chrome->legacyPageSettings('documents');
	}

	public function testByElementLoadsTheDeclaredLegacyClassAndSharesTheMemo(): void
	{
		$loader = new LegacyObjectLoader(new Connection(LegacyContext::fromGlobals()->db));
		$definition = (new UserModule())->entities()[0];

		$object = $loader->byElement($definition, 1);

		self::assertInstanceOf(\User::class, $object);
		self::assertSame(1, (int) $object->id);
		self::assertSame($object, $loader->user(1));
		self::assertNull($loader->byElement($definition, 999999));
	}

	public function testByElementWithoutLegacyClassThrows(): void
	{
		$loader = new LegacyObjectLoader(new Connection(LegacyContext::fromGlobals()->db));

		$this->expectException(\LogicException::class);
		$loader->byElement(new EntityDefinition('thing', \stdClass::class, 'r', 'thing', 'thing', 'p', 't', 's'), 1);
	}
}
