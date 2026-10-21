<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\Notes;

use Dolibarr\Tests\Unit\Module\User\UserLegacyRedirectsTest;
use PHPUnit\Framework\TestCase;

/**
 * The Notes component declares no legacy script of its own: user/note.php is declared by the User module
 * (fixed entity=user) and goes to the generic entity_note route.
 */
final class NotesLegacyRedirectsTest extends TestCase
{
	public function testUserNoteGoesToTheGenericEntityNoteRoute(): void
	{
		$r = UserLegacyRedirectsTest::redirector();
		self::assertSame('/user/9/note', $r->resolve('GET', 'user/note.php', ['id' => '9'], []));
		self::assertSame('/user/9/note?action=editnote_private', $r->resolve('GET', 'user/note.php', ['id' => '9', 'action' => 'editnote_private'], []));
		self::assertNull($r->resolve('GET', 'user/note.php', ['id' => '9', 'action' => 'setnote_private'], []));
		self::assertNull($r->resolve('POST', 'user/note.php', ['id' => '9'], []));
	}

	public function testNotesModuleDeclaresNoRule(): void
	{
		self::assertSame([], (new \Dolibarr\Module\Notes\NotesModule())->legacyRedirects());
	}
}
