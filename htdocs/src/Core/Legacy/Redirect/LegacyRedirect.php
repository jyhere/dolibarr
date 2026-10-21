<?php

declare(strict_types=1);

namespace Dolibarr\Core\Legacy\Redirect;

/**
 * Immutable declarative rule: a legacy script (and the conditions on its parameters) redirected to a route.
 *
 * @phpstan-type RuleArray array{script: string, route: string, paramMap: array<string,string>, fixedParams: array<string,scalar>, required: list<string>, actions: ?list<string>, forbidden: list<string>, carry: list<string>, queryFromAction: array<string,array<string,scalar>>, requirements: array<string,string>, forbiddenValues: array<string,list<string>>}
 */
final class LegacyRedirect
{
	/**
	 * @param string                              $script          Path relative to htdocs, e.g. 'user/card.php'
	 * @param string                              $route           Target route name
	 * @param array<string,string>                $paramMap        Legacy parameter => route variable, e.g. ['id' => 'id']
	 * @param array<string,scalar>                $fixedParams     Constant route variables, e.g. ['entity' => 'user']
	 * @param list<string>                        $required        Legacy parameters that must be present and not empty
	 * @param list<string>|null                   $actions         Allowed values of 'action' ('' = absent or empty); null = any
	 * @param list<string>                        $forbidden       Parameters that prevent the redirection when present and not empty
	 * @param list<string>                        $carry           Legacy parameters copied as is to the target query string (whitelist, scalar values only)
	 * @param array<string,array<string,scalar>>  $queryFromAction Legacy action => query parameters added, e.g. ['disable' => ['confirm' => 'disable']]
	 * @param array<string,string>                $requirements    Legacy parameter => regex (anchored by the resolver), e.g. ['id' => '\d+']
	 * @param array<string,list<string>>          $forbiddenValues Legacy parameter => values that prevent the redirection, e.g. ['contextpage' => ['employeelist']]
	 */
	public function __construct(
		public readonly string $script,
		public readonly string $route,
		public readonly array $paramMap = [],
		public readonly array $fixedParams = [],
		public readonly array $required = [],
		public readonly ?array $actions = [''],
		public readonly array $forbidden = ['massaction', 'confirm'],
		public readonly array $carry = [],
		public readonly array $queryFromAction = [],
		public readonly array $requirements = [],
		public readonly array $forbiddenValues = []
	) {
	}

	/**
	 * @return RuleArray
	 */
	public function toArray(): array
	{
		return [
			'script' => $this->script,
			'route' => $this->route,
			'paramMap' => $this->paramMap,
			'fixedParams' => $this->fixedParams,
			'required' => $this->required,
			'actions' => $this->actions,
			'forbidden' => $this->forbidden,
			'carry' => $this->carry,
			'queryFromAction' => $this->queryFromAction,
			'requirements' => $this->requirements,
			'forbiddenValues' => $this->forbiddenValues,
		];
	}

	/**
	 * @param RuleArray $a
	 */
	public static function fromArray(array $a): self
	{
		return new self(
			$a['script'],
			$a['route'],
			$a['paramMap'],
			$a['fixedParams'],
			$a['required'],
			$a['actions'],
			$a['forbidden'],
			$a['carry'],
			$a['queryFromAction'],
			$a['requirements'],
			$a['forbiddenValues'] ?? []
		);
	}
}
