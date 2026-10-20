<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy;

use Dolibarr\Core\Session;

/**
 * Session over $_SESSION and the session_*() functions. With FlashBag (setEventMessages()) and
 * LegacyContext::authMode() (read by the legacy user card chrome), the only new code touching them.
 */
final class LegacySession implements Session
{
	public function get(string $key, mixed $default = null): mixed
	{
		return $_SESSION[$key] ?? $default;
	}

	public function set(string $key, mixed $value): void
	{
		$_SESSION[$key] = $value;
	}

	public function remove(string $key): void
	{
		unset($_SESSION[$key]);
	}

	public function has(string $key): bool
	{
		return isset($_SESSION[$key]);
	}

	/**
	 * session_destroy() when a session is active; $_SESSION is emptied in any case, since session_destroy()
	 * leaves it filled for the rest of the request.
	 */
	public function destroy(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}
		$_SESSION = [];
	}

	/**
	 * session_regenerate_id(true): the old session is deleted. Nothing to do without an active session.
	 */
	public function regenerate(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE && !session_regenerate_id(true)) {
			throw new \RuntimeException('Session id could not be regenerated');
		}
	}

	public function id(): string
	{
		$id = session_id();

		return is_string($id) ? $id : '';
	}
}
