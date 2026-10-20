<?php

declare(strict_types=1);

namespace Dolibarr\Core;

/**
 * The PHP session shared with the legacy pages.
 */
interface Session
{
	public function get(string $key, mixed $default = null): mixed;

	public function set(string $key, mixed $value): void;

	public function remove(string $key): void;

	/**
	 * Whether the key holds a value; a null value counts as absent, as with isset().
	 */
	public function has(string $key): bool;

	/**
	 * Destroy the session and empty its data for the rest of the request.
	 */
	public function destroy(): void;

	/**
	 * Give the session a new id, keeping its data.
	 *
	 * @throws \RuntimeException When the id could not be regenerated
	 */
	public function regenerate(): void;

	/**
	 * The session id, '' when no session is started.
	 */
	public function id(): string;
}
