<?php

declare(strict_types=1);

namespace Dolibarr\Core\Http;

final class HtmlResponse extends Response
{
	/**
	 * @param array<string,string> $headers
	 */
	public function __construct(string $html, int $status = 200, array $headers = [])
	{
		parent::__construct($status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers, $html);
	}
}
