<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Fixture;

use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\LegacyInputFilter;

/**
 * A request as Kernel::requestForRun() builds it: its parameters are also put into $_GET and $_POST,
 * which GETPOST() reads. A test using it saves and restores both superglobals.
 */
final class GlobalsRequest
{
	/**
	 * @param array<string,mixed> $query
	 * @param array<string,mixed> $post
	 */
	public static function create(string $method, string $path, array $query = [], array $post = []): Request
	{
		$_GET = $query;
		$_POST = $post;

		return (new Request($method, $path, $query, $post, [], []))->withInputFilter(new LegacyInputFilter());
	}
}
