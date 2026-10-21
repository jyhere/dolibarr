<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Http;

use Dolibarr\Core\Http\JsonResponse;
use Dolibarr\Core\Http\RedirectResponse;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
	public function testJsonResponseEncodesUnicodeAndSlashes(): void
	{
		self::assertSame('{"a":"é/x"}', (new JsonResponse(['a' => 'é/x']))->body());
	}

	public function testRedirectSetsLocationAnd302(): void
	{
		$response = new RedirectResponse('/login');
		self::assertSame(302, $response->status());
		self::assertSame('/login', $response->headers()['Location']);
	}
}
