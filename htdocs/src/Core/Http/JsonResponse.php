<?php

declare(strict_types=1);

namespace Dolibarr\Core\Http;

final class JsonResponse extends Response
{
	public function __construct(mixed $data, int $status = 200)
	{
		parent::__construct(
			$status,
			['Content-Type' => 'application/json; charset=UTF-8'],
			json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
		);
	}
}
