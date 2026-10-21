<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy\Redirect;

use Dolibarr\Core\Routing\UrlGenerator;

/**
 * Pure resolver: the target URL of a legacy request, or null when it must be served by the legacy page.
 */
final class LegacyRedirector
{
	/**
	 * Display and context parameters read by main.inc.php on any page: carried by every rule, after its own carry.
	 */
	public const GLOBAL_CARRY = [
		'dol_hide_topmenu', 'dol_hide_leftmenu', 'dol_optimize_smallscreen', 'dol_no_mouse_hover', 'dol_use_jmobile',
		'optioncss', 'lang', 'mainmenu', 'leftmenu',
	];

	public function __construct(private readonly LegacyRedirectTable $table, private readonly UrlGenerator $urls)
	{
	}

	/**
	 * Only GET/HEAD, never AJAX; the first rule of the script whose conditions all pass wins.
	 * A parameter is "present" when set and not ''. Only scalar values are mapped or carried (arrays never are).
	 * Carried: the rule's carry list, then GLOBAL_CARRY.
	 *
	 * @param array<mixed>  $query   Legacy query parameters
	 * @param array<string,string> $headers Request headers
	 */
	public function resolve(string $method, string $script, array $query, array $headers): ?string
	{
		if (!in_array(strtoupper($method), ['GET', 'HEAD'], true) || $this->isAjax($headers)) {
			return null;
		}

		foreach ($this->table->forScript($script) as $rule) {
			$url = $this->apply($rule, $query);
			if ($url !== null) {
				return $url;
			}
		}

		return null;
	}

	/**
	 * @param array<string,string> $headers
	 */
	private function isAjax(array $headers): bool
	{
		foreach ($headers as $name => $value) {
			if (strtolower((string) $name) === 'x-requested-with' && strtolower(trim($value)) === 'xmlhttprequest') {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<mixed> $query
	 */
	private function present(array $query, string $name): bool
	{
		return isset($query[$name]) && $query[$name] !== '';
	}

	/**
	 * @param array<mixed> $query
	 */
	private function apply(LegacyRedirect $rule, array $query): ?string
	{
		$rawAction = $query['action'] ?? '';
		if (!is_scalar($rawAction)) {
			return null;
		}
		$action = trim((string) $rawAction);
		if ($rule->actions !== null && !in_array($action, $rule->actions, true)) {
			return null;
		}
		foreach ($rule->forbidden as $name) {
			if ($this->present($query, $name)) {
				return null;
			}
		}
		foreach ($rule->forbiddenPrefixes as $prefix) {
			foreach ($query as $name => $value) {
				if (str_starts_with((string) $name, $prefix) && $value !== '' && $value !== null) {
					return null;
				}
			}
		}
		foreach ($rule->forbiddenValues as $name => $values) {
			$value = $query[$name] ?? null;
			if (is_scalar($value) && in_array(trim((string) $value), $values, true)) {
				return null;
			}
		}
		foreach ($rule->required as $name) {
			if (!$this->present($query, $name)) {
				return null;
			}
		}
		foreach ($rule->requirements as $name => $regex) {
			if (!$this->present($query, (string) $name)) {
				continue;
			}
			$value = $query[$name];
			if (!is_scalar($value) || preg_match('~^(?:'.str_replace('~', '\~', $regex).')$~D', (string) $value) !== 1) {
				return null;
			}
		}

		$params = [];
		foreach ($rule->paramMap as $legacy => $target) {
			if (!$this->present($query, (string) $legacy)) {
				continue;
			}
			$value = $query[$legacy];
			if (!is_scalar($value)) {
				return null;
			}
			if (in_array(trim((string) $value), ['.', '..'], true)) {
				return null;
			}
			$params[$target] = (string) $value;
		}
		foreach ($rule->fixedParams as $name => $value) {
			$params[(string) $name] = $value;
		}

		$extra = [];
		foreach (array_unique(array_merge($rule->carry, self::GLOBAL_CARRY)) as $name) {
			if ($this->present($query, $name) && is_scalar($query[$name])) {
				$extra[$name] = (string) $query[$name];
			}
		}
		foreach ($rule->queryFromAction[$action] ?? [] as $name => $value) {
			$extra[(string) $name] = $value;
		}

		try {
			$url = $this->urls->generate($rule->route, $params);
		} catch (\InvalidArgumentException) {
			// A route variable is not supplied by this request: the legacy page serves it.
			return null;
		}

		return $extra === [] ? $url : $url.'?'.http_build_query($extra, '', '&', PHP_QUERY_RFC3986);
	}
}
