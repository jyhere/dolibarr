<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

/**
 * The path exists but not for the requested method (HTTP 405).
 */
final class MethodNotAllowedException extends \RuntimeException
{
	/**
	 * @param list<string> $allowed
	 */
	public function __construct(private readonly array $allowed)
	{
		parent::__construct('Method not allowed. Allowed: '.implode(', ', $allowed));
	}

	/**
	 * @return list<string>
	 */
	public function allowed(): array
	{
		return $this->allowed;
	}
}
