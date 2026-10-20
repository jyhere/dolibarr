<?php

declare(strict_types=1);

namespace Dolibarr\Core\Entity;

/**
 * What a legacy tab page of an entity sets up before its actions (e.g. htdocs/user/note.php): the translation
 * domains it loads, the hook contexts it initialises and the parameters it gives to the doActions hook.
 */
final class LegacyPageSettings
{
	/**
	 * @param list<string>         $translationDomains $langs->loadLangs(...)
	 * @param list<string>         $hookContexts       $hookmanager->initHooks(...)
	 * @param array<string, mixed> $doActionsParams    $parameters of executeHooks('doActions', ...)
	 *
	 * @throws \InvalidArgumentException When a domain or a context is not a non-empty string
	 */
	public function __construct(
		public readonly array $translationDomains,
		public readonly array $hookContexts,
		public readonly array $doActionsParams = []
	) {
		foreach ([...$translationDomains, ...$hookContexts] as $name) {
			if (!is_string($name) || $name === '') { // @phpstan-ignore function.alreadyNarrowedType
				throw new \InvalidArgumentException('Translation domains and hook contexts must be non-empty strings');
			}
		}
		if (!array_is_list($translationDomains) || !array_is_list($hookContexts)) { // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType
			throw new \InvalidArgumentException('Translation domains and hook contexts must be lists');
		}
	}
}
