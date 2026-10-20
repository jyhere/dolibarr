<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Legacy;

use Dolibarr\Core\Legacy\LegacyInputFilter;
use PHPUnit\Framework\TestCase;

final class LegacyInputFilterTest extends TestCase
{
	/** @var array<mixed> */
	private array $get = [];

	/** @var array<mixed> */
	private array $post = [];

	protected function setUp(): void
	{
		$this->get = $_GET;
		$this->post = $_POST;
		$_GET = [];
		$_POST = [];
	}

	protected function tearDown(): void
	{
		$_GET = $this->get;
		$_POST = $this->post;
	}

	public function testAlphanohtmlStripsTags(): void
	{
		$_POST['x'] = '<b>a</b>';
		self::assertSame('a', $this->filter()->filter('x', 'alphanohtml'));
	}

	/**
	 * The brief expected 12 for '12abc': GETPOSTINT() returns 0 for a non numeric value (sanitizeVal 'int'
	 * empties it), and the filter must behave as GETPOSTINT().
	 */
	public function testIntFilterCasts(): void
	{
		$_GET['id'] = '12';
		self::assertSame(12, $this->filter()->filter('id', 'int'));
		$_GET['id'] = '12abc';
		self::assertSame(0, $this->filter()->filter('id', 'int'));
	}

	public function testRestricthtmlKeepsSafeTags(): void
	{
		$_POST['n'] = '<b>ok</b><script>x</script>';
		$v = $this->filter()->filter('n', 'restricthtml');
		self::assertIsString($v);
		self::assertStringContainsString('<b>ok</b>', $v);
		self::assertStringNotContainsString('<script', $v);
	}

	public function testMissingKeyReturnsTheDefault(): void
	{
		self::assertNull($this->filter()->filter('absent', 'alphanohtml'));
		self::assertSame(5, $this->filter()->filter('absent', 'int', 5));
	}

	public function testGetIsReadBeforePostAsGetpost(): void
	{
		$_GET['k'] = 'fromget';
		$_POST['k'] = 'frompost';
		self::assertSame('fromget', $this->filter()->filter('k', 'alphanohtml'));
	}

	public function testFloatFilter(): void
	{
		$_POST['amount'] = '12.5';
		self::assertSame(12.5, $this->filter()->filter('amount', 'float'));
	}

	public function testArrayIntFilter(): void
	{
		$_POST['ids'] = ['3', '4'];
		self::assertSame([3, 4], array_map('intval', (array) $this->filter()->filter('ids', 'array:int')));
	}

	public function testDateFilterCombinesTheDateSelectorFields(): void
	{
		$_POST['datec'] = '20/10/2026';
		$_POST['datecday'] = '20';
		$_POST['datecmonth'] = '10';
		$_POST['datecyear'] = '2026';
		self::assertSame(dol_mktime(0, 0, 0, 10, 20, 2026, 'tzuserrel'), $this->filter()->filter('datec', 'date'));
		self::assertNull($this->filter()->filter('dateabsent', 'date'));
	}

	public function testUrlParameterGetsTheGetpostUrlCleaning(): void
	{
		$_GET['backtopage'] = '//evil.example/x';
		self::assertSame('evil.example/x', $this->filter()->filter('backtopage', 'alphanohtml'));
	}

	public function testIsSet(): void
	{
		$_GET['empty'] = '';
		self::assertTrue($this->filter()->isSet('empty'));
		self::assertFalse($this->filter()->isSet('absent'));
	}

	private function filter(): LegacyInputFilter
	{
		return new LegacyInputFilter();
	}
}
