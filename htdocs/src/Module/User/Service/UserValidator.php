<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Service;

use Dolibarr\Core\Config;
use Dolibarr\Core\Translator;

/**
 * The checks htdocs/user/card.php makes before saving (l.287-310 and l.478-496), plus those of
 * \User::create() and \User::update() (login bad characters, USER_MAIL_REQUIRED).
 */
final class UserValidator
{
	public function __construct(private readonly Translator $translator, private readonly Config $config)
	{
	}

	/**
	 * @param bool $isCreate           A creation (the users quota applies), not an update
	 * @param int  $currentUsersCount  Number of active users, as \User::getNbOfUsers('active')
	 *
	 * @return list<string> Translated messages, empty when the input is valid
	 */
	public function validate(UserInput $in, bool $isCreate, int $currentUsersCount): array
	{
		$this->translator->load('errors');
		$errors = [];

		if (self::isEmpty($in->lastname)) {
			$errors[] = $this->translator->trans('NameNotDefined');
		}
		if (self::isEmpty($in->login)) {
			$errors[] = $this->translator->trans('LoginNotDefined');
		}

		$limit = $this->config->limitUsers();
		if ($isCreate && $limit > 0 && $currentUsersCount >= $limit) {
			$errors[] = $this->translator->trans('YourQuotaOfUsersIsReached');
		}

		if ($in->signature === 'ErrorTooManyLinksIntoHTMLString') {
			// restricthtml swaps a signature with too many images for this literal: refuse, never store it.
			$errors[] = $this->translator->trans('ErrorTooManyLinksIntoHTMLString');
		}

		if ($in->extraFieldsError !== null) {
			$errors[] = $in->extraFieldsError;
		}

		$badChars = $this->config->loginBadCharUnauthorized();
		if (!self::isEmpty($in->login) && $badChars !== '' && preg_match('/['.preg_quote($badChars, '/').']/', $in->login) === 1) {
			$errors[] = $this->translator->trans('ErrorBadCharIntoLoginName', $this->translator->transnoentities('Login'));
		}

		if ($this->config->string('USER_MAIL_REQUIRED') !== '' && $this->config->string('USER_MAIL_REQUIRED') !== '0'
			&& filter_var($in->email ?? '', FILTER_VALIDATE_EMAIL) === false) {
			// The email posted goes into a flash message (HTML)
			$errors[] = $this->translator->transHtml('ErrorBadEMail', $in->email ?? '');
		}

		return $errors;
	}

	/**
	 * PHP empty() on a string: '' and '0' are empty, as for !GETPOST().
	 */
	private static function isEmpty(?string $value): bool
	{
		return $value === null || $value === '' || $value === '0';
	}
}
