<?php

declare(strict_types=1);

namespace Dolibarr\Core\Http;

final class RedirectResponse extends Response
{
	public function __construct(string $url, int $status = 302)
	{
		parent::__construct($status, ['Location' => $url]);
	}
}
