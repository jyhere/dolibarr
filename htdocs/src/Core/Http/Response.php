<?php

declare(strict_types=1);

namespace Dolibarr\Core\Http;

abstract class Response
{
	/**
	 * @param array<string,string> $headers
	 */
	public function __construct(
		private readonly int $status = 200,
		private readonly array $headers = [],
		private readonly string $body = ''
	) {
	}

	public function status(): int
	{
		return $this->status;
	}

	/**
	 * @return array<string,string>
	 */
	public function headers(): array
	{
		return $this->headers;
	}

	public function body(): string
	{
		return $this->body;
	}

	public function send(): void
	{
		if (!headers_sent()) {
			http_response_code($this->status);
			foreach ($this->headers as $name => $value) {
				header($name . ': ' . $value);
			}
		}
		echo $this->body;
	}
}
