<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Legacy\Redirect;

use Dolibarr\Core\Legacy\Redirect\LegacyRedirect;
use Dolibarr\Core\Legacy\Redirect\RedirectCache;
use PHPUnit\Framework\TestCase;

final class RedirectCacheTest extends TestCase
{
	public function testRoundTripAndStaleStamp(): void
	{
		$file = sys_get_temp_dir().'/redirects_'.uniqid().'.php';
		try {
			$rows = [(new LegacyRedirect('user/card.php', 'user_show', ['id' => 'id'], [], ['id'], [''], ['massaction'], ['backtopage'], ['disable' => ['confirm' => 'disable']], ['id' => '\d+']))->toArray()];
			$cache = new RedirectCache($file);
			$cache->store($rows, '1.0|abc');

			self::assertSame($rows, $cache->load('1.0|abc'));
			self::assertNull($cache->load('2.0|abc'));
		} finally {
			@unlink($file);
		}
	}

	public function testMissingAndMalformedCacheIsIgnored(): void
	{
		$file = sys_get_temp_dir().'/redirects_'.uniqid().'.php';
		try {
			self::assertNull((new RedirectCache($file))->load('s'));
			file_put_contents($file, '<?php return ["stamp" => "s", "rules" => [["script" => "x"]]];');
			self::assertNull((new RedirectCache($file))->load('s'));
			file_put_contents($file, '<?php return 42;');
			self::assertNull((new RedirectCache($file))->load('s'));
		} finally {
			@unlink($file);
		}
	}

	public function testLegacyRedirectArrayRoundTrip(): void
	{
		$rule = new LegacyRedirect('user/list.php', 'user_list', actions: null, carry: ['sortfield']);

		self::assertEquals($rule, LegacyRedirect::fromArray($rule->toArray()));
	}
}
