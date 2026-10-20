<?php

declare(strict_types=1);

namespace Dolibarr\Core\Http;

/**
 * Immutable HTTP request value object.
 */
final class Request
{
	private readonly string $method;
	private readonly string $path;
	private readonly bool $jsonSuffix;

	/** @var array<string,string> header values indexed by lower-case name */
	private readonly array $headers;

	/**
	 * @param string               $method  HTTP method
	 * @param string               $path    Raw request path (may contain a query string)
	 * @param array<string,mixed>  $query   Query parameters
	 * @param array<string,mixed>  $post    Body parameters
	 * @param array<string,string> $headers Headers, any case
	 * @param array<string,string> $cookies Cookies
	 */
	public function __construct(
		string $method,
		string $path,
		private readonly array $query,
		private readonly array $post,
		array $headers,
		private readonly array $cookies,
		string $urlRoot = ''
	) {
		$this->method = strtoupper($method);

		$normalized = [];
		foreach ($headers as $name => $value) {
			$normalized[strtolower((string) $name)] = $value;
		}
		$this->headers = $normalized;

		$path = explode('?', $path, 2)[0];
		$path = rawurldecode($path);
		$path = (string) preg_replace('#/{2,}#', '/', $path);
		if ($urlRoot !== '' && str_starts_with($path, $urlRoot)) {
			$path = substr($path, strlen($urlRoot));
		}
		$this->jsonSuffix = str_ends_with($path, '.json');
		if ($this->jsonSuffix) {
			$path = substr($path, 0, -5);
		}
		if ($path !== '/') {
			$path = rtrim($path, '/');
		}
		if ($path === '' || $path[0] !== '/') {
			$path = '/' . $path;
		}
		$this->path = $path;
	}

	/**
	 * Build the request from PHP superglobals.
	 *
	 * @param string $urlRoot DOL_URL_ROOT (often empty)
	 */
	public static function fromGlobals(string $urlRoot): self
	{
		$headers = [];
		foreach ($_SERVER as $key => $value) {
			if (!is_string($value)) {
				continue;
			}
			if (str_starts_with($key, 'HTTP_')) {
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
				$headers[$name] = $value;
			} elseif ($key === 'CONTENT_TYPE') {
				$headers['Content-Type'] = $value;
			} elseif ($key === 'CONTENT_LENGTH') {
				$headers['Content-Length'] = $value;
			}
		}

		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$uri = $_SERVER['REQUEST_URI'] ?? '/';
		/** @var array<string,string> $cookies */
		$cookies = $_COOKIE;

		return new self(
			is_string($method) ? $method : 'GET',
			is_string($uri) ? $uri : '/',
			$_GET,
			$_POST,
			$headers,
			$cookies,
			$urlRoot
		);
	}

	public function method(): string
	{
		return $this->method;
	}

	public function path(): string
	{
		return $this->path;
	}

	public function isPost(): bool
	{
		return $this->method === 'POST';
	}

	public function wantsJson(): bool
	{
		if ($this->jsonSuffix || $this->query('format') === 'json') {
			return true;
		}
		$accept = $this->header('Accept');
		if ($accept === null) {
			return false;
		}
		$first = trim(explode(';', explode(',', $accept)[0])[0]);

		return strtolower($first) === 'application/json';
	}

	public function query(string $key, ?string $default = null): ?string
	{
		$value = $this->query[$key] ?? null;

		return is_string($value) ? $value : $default;
	}

	public function post(string $key, ?string $default = null): ?string
	{
		$value = $this->post[$key] ?? null;

		return is_string($value) ? $value : $default;
	}

	public function header(string $name): ?string
	{
		return $this->headers[strtolower($name)] ?? null;
	}

	public function cookie(string $name, ?string $default = null): ?string
	{
		$value = $this->cookies[$name] ?? null;

		return is_string($value) ? $value : $default;
	}

	public function has(string $key): bool
	{
		return array_key_exists($key, $this->query) || array_key_exists($key, $this->post);
	}
}
