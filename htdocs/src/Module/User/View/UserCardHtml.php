<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\View;

/**
 * Trusted HTML fragments of the user card produced by legacy helpers.
 * A null fragment means the row is not shown. Not Exposable: never part of JSON output.
 */
final class UserCardHtml
{
	public function __construct(
		public readonly string $confirmHtml,
		public readonly string $tabsHtml,
		public readonly string $bannerHtml,
		public readonly string $tabsEndHtml,
		public readonly string $loginHtml,
		public readonly string $typeLabelHtml,
		public readonly ?string $supervisorHtml,
		public readonly ?string $expenseValidatorLabelHtml,
		public readonly ?string $expenseValidatorHtml,
		public readonly ?string $holidayValidatorLabelHtml,
		public readonly ?string $holidayValidatorHtml,
		public readonly string $weeklyHours,
		public readonly ?string $salaryHtml,
		public readonly ?string $thmLabelHtml,
		public readonly ?string $thmHtml,
		public readonly ?string $tjmLabelHtml,
		public readonly ?string $tjmHtml,
		public readonly ?string $colorHtml,
		public readonly ?string $langLabelHtml,
		public readonly ?string $langHtml,
		public readonly ?string $companyHtml,
		public readonly ?string $memberHtml,
		public readonly string $signatureHtml,
		public readonly string $securityPictoHtml,
		public readonly ?string $passwordToChangeLabelHtml,
		public readonly ?string $passwordToChangeHtml,
		public readonly ?string $lastLoginHtml,
		public readonly string $actionsHtml
	) {
	}
}
