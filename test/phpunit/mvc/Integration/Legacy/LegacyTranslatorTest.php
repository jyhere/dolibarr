<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\LegacyTranslator;
use PHPUnit\Framework\TestCase;

final class LegacyTranslatorTest extends TestCase
{
	public function testTransHtmlEscapesTheArgumentsOnly(): void
	{
		$langs = $GLOBALS['langs'];
		$langs->load('users');
		$t = new LegacyTranslator($langs);

		$html = $t->transHtml('PasswordChangedTo', '<b>"x"</b>');

		self::assertStringContainsString('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $html);
		self::assertStringNotContainsString('<b>"x"', $html);
		self::assertSame($t->trans('PasswordChangedTo', '&lt;b&gt;&quot;x&quot;&lt;/b&gt;'), $html, 'the translation itself is trans(), its tags kept');
	}
}
