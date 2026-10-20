<?php

declare(strict_types=1);

namespace Dolibarr\Core\View;

use Dolibarr\Core\Legacy\LegacyContext;

/**
 * A confirmation dialog, as Form::formconfirm() renders it. Always the HTML form (useajax = 0): it posts
 * action=<confirmAction>, confirm=yes|no and the CSRF token to $actionUrl, so the confirmed action is never a GET.
 */
final class Confirm
{
	public function __construct(private readonly LegacyContext $ctx)
	{
	}

	/**
	 * @param array<mixed> $questions The $formquestion of Form::formconfirm(), [] for none
	 * @return string Trusted legacy HTML
	 */
	public function render(string $actionUrl, string $title, string $question, string $confirmAction, array $questions = [], string $defaultAnswer = 'yes', int $height = 0, int $width = 500): string
	{
		$form = $this->ctx->form();
		ob_start();
		try {
			$html = $form->formconfirm($actionUrl, $title, $question, $confirmAction, $questions === [] ? '' : $questions, $defaultAnswer, 0, $height, $width);
		} finally {
			$printed = (string) ob_get_clean();
		}

		return $printed.$html;
	}
}
