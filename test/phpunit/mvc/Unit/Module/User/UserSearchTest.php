<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Core\Http\Request;
use Dolibarr\Module\User\Repository\UserSearch;
use Dolibarr\Tests\Unit\Http\Fixture\FakeInputFilter;
use Dolibarr\Tests\Unit\Module\User\Fixture\ArrayConfig;
use PHPUnit\Framework\TestCase;

/**
 * UserSearch: the parameters of htdocs/user/list.php l.51-79 and l.179-224, clamped (Review Focus 4).
 */
final class UserSearchTest extends TestCase
{
	public function testLimitAndPageAreClamped(): void
	{
		$s = $this->search(['limit' => 0, 'page' => -3]);
		self::assertSame(20, $s->limit);
		self::assertSame(0, $s->page);

		$s = $this->search(['limit' => 5000, 'page' => 2]);
		self::assertSame(20, $s->limit);
		self::assertSame(2, $s->page);

		$s = $this->search(['limit' => 50, 'page' => 3]);
		self::assertSame(50, $s->limit);
		self::assertSame(3, $s->page);
		self::assertSame(150, $s->offset());

		// list.php l.72-76: pageplusone wins, a new search goes back to the first page
		self::assertSame(4, $this->search(['pageplusone' => 5, 'page' => 1])->page);
		self::assertSame(0, $this->search(['page' => 3, 'button_search' => 'x'])->page);
		self::assertSame(0, $this->search(['page' => 3, 'button_removefilter' => 'x'])->page);
	}

	public function testUnknownSortFieldFallsBackToLogin(): void
	{
		self::assertSame('u.login', $this->search([])->sortField);
		self::assertSame('u.login', $this->search(['sortfield' => 'u.pass_crypted'])->sortField);
		self::assertSame('u.login', $this->search(['sortfield' => 'u.login,u.pass'])->sortField);
		self::assertSame('u.login', $this->search(['sortfield' => 'ef.secret'])->sortField);
		self::assertSame('u.email', $this->search(['sortfield' => 'u.email'])->sortField);
		self::assertSame('date_modification', $this->search(['sortfield' => 'date_modification'])->sortField);
		self::assertSame('u.lastname,u.firstname', $this->search(['sortfield' => 'u.lastname,u.firstname'])->sortField);
		// An extrafield column is sortable when it is one of the list
		self::assertSame('ef.myfield', $this->search(['sortfield' => 'ef.myfield'], ['ef.myfield'])->sortField);
	}

	public function testSortOrderWhitelist(): void
	{
		self::assertSame('ASC', $this->search([])->sortOrder);
		self::assertSame('DESC', $this->search(['sortorder' => 'desc'])->sortOrder);
		self::assertSame('ASC', $this->search(['sortorder' => 'DESC;DROP'])->sortOrder);
		self::assertSame('ASC', $this->search(['sortorder' => 'RAND'])->sortOrder);
	}

	public function testStatusDefaultsToEnabledUnlessSearchAll(): void
	{
		// list.php l.219-221
		self::assertSame('1', $this->search([])->filters['search_status']);
		self::assertSame('0', $this->search(['search_status' => '0'])->filters['search_status']);
		self::assertSame('', $this->search(['search_all' => 'bob'])->filters['search_status']);
		self::assertSame('', $this->search(['search_status' => '1'])->reset()->filters['search_status']);
	}

	public function testEmployeeListForcesEmployee(): void
	{
		// list.php l.222-224
		$s = $this->search([], [], true);
		self::assertSame('1', $s->filters['search_employee']);
		self::assertSame('employeelist', $s->contextpage);
		self::assertSame('userlist', $this->search([])->contextpage);
		self::assertSame('', $this->search(['search_employee' => ''], [], true)->filters['search_employee']);
	}

	public function testOptionCssAndModeWhitelist(): void
	{
		self::assertSame('print', $this->search(['optioncss' => 'print'])->optioncss);
		self::assertSame('', $this->search(['optioncss' => 'other'])->optioncss);
		self::assertSame('kanban', $this->search(['mode' => 'kanban'])->mode);
		self::assertSame('', $this->search(['mode' => 'weird'])->mode);
	}

	public function testParamCarriesTheFilters(): void
	{
		$s = $this->search(['search_login' => 'a b', 'limit' => 50, 'search_status' => '0']);

		self::assertSame('&contextpage=userlist&limit=50&search_login=a+b&search_status=0', $s->param(20));
	}

	/**
	 * @param array<string, mixed> $values
	 * @param list<string>         $extraSortable
	 */
	private function search(array $values, array $extraSortable = [], bool $employeeList = false): UserSearch
	{
		if ($employeeList) {
			$values['contextpage'] = 'employeelist';
		}
		$r = (new Request('GET', '/user', [], [], [], []))->withInputFilter(new FakeInputFilter($values));

		return UserSearch::fromRequest($r, new ArrayConfig(), $employeeList, 20, $extraSortable);
	}
}
