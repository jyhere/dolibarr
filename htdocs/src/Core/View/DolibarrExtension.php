<?php

declare(strict_types=1);

namespace Dolibarr\Core\View;

use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Translator;
use Latte\Extension;
use Latte\Runtime\Html;

/**
 * Latte filters and functions bridging to Dolibarr services and legacy helpers.
 *
 * Dolibarr global functions are resolved lazily, at call time.
 */
final class DolibarrExtension extends Extension
{
	private const LEGACY_PREFIXES = [
		'dol_', 'img_', 'price', 'newToken', 'currentToken',
		'complete_head_from_modules', 'user_prepare_head', 'llx',
	];

	/**
	 * @param (\Closure(string, array<string,mixed>): string)|null $legacyHook
	 */
	public function __construct(
		private readonly Translator $translator,
		private readonly ?CurrentUser $user,
		private readonly UrlGenerator $urls,
		private readonly ?\Closure $legacyHook = null
	) {
	}

	/**
	 * @return array<string, callable>
	 */
	public function getFilters(): array
	{
		return [
			'trans' => fn (string $key, string ...$args): string => $this->translator->trans($key, ...$args),
			'transnoentities' => fn (string $key, string ...$args): string => $this->translator->transnoentities($key, ...$args),
			'url' => fn (string $name, array $params = []): string => $this->urls->generate($name, $params),
			'date' => $this->date(...),
			'price' => fn (float|int|string $value): string => $this->global('price', $value),
			'picto' => fn (string $name): Html => new Html($this->global('img_picto', '', $name)),
		];
	}

	/**
	 * @return array<string, callable>
	 */
	public function getFunctions(): array
	{
		return [
			'token' => fn (): string => $this->global('newToken'),
			'hasRight' => fn (string $module, string $perm, string $subperm = ''): bool => $this->user !== null && $this->user->hasRight($module, $perm, $subperm),
			'legacyHook' => fn (string $name, array $params = []): Html => new Html($this->legacyHook === null ? '' : ($this->legacyHook)($name, $params)),
			'legacy' => $this->callLegacy(...),
		];
	}

	/**
	 * dol_print_date() of a timestamp or a date object; '' for null, like dol_print_date('').
	 * Entities hold DATE columns as UTC midnight: print them with $tz = 'gmt'.
	 */
	private function date(\DateTimeInterface|int|null $value, string $format = 'day', string $tz = 'auto'): string
	{
		if ($value === null) {
			return '';
		}

		return $this->global('dol_print_date', $value instanceof \DateTimeInterface ? $value->getTimestamp() : $value, $format, $tz);
	}

	/**
	 * @throws \InvalidArgumentException If the function is not whitelisted
	 */
	private function callLegacy(string $fn, mixed ...$args): mixed
	{
		$allowed = false;
		foreach (self::LEGACY_PREFIXES as $prefix) {
			if (str_starts_with($fn, $prefix)) {
				$allowed = true;
				break;
			}
		}
		if (!$allowed || !function_exists($fn)) {
			throw new \InvalidArgumentException('Legacy function "'.$fn.'" is not allowed from templates');
		}

		return $fn(...$args);
	}

	/**
	 * Call a Dolibarr global function, resolved at call time.
	 */
	private function global(string $fn, mixed ...$args): string
	{
		return (string) $fn(...$args);
	}
}
