<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Entity;

use Dolibarr\Core\View\Exposable;

/**
 * A user as exposed to a given viewer: the entity filtered by the visibility
 * policy, HR fields removed when the viewer may not see salaries.
 */
final class UserView implements Exposable
{
	/** Keys hidden from the JSON output without the salary permission. */
	public const SALARY_KEYS = ['salary', 'salaryExtra', 'thm', 'tjm', 'weeklyHours', 'dateEmployment', 'dateEmploymentEnd'];

	public function __construct(
		public readonly User $user,
		public readonly Visibility $visibility,
		public readonly bool $canSeeSalary = true
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$data = $this->user->toArray($this->visibility);

		return $this->canSeeSalary ? $data : array_diff_key($data, array_flip(self::SALARY_KEYS));
	}
}
