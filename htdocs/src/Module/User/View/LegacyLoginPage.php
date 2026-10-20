<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\View;

use Dolibarr\Core\Legacy\LegacyContext;

/**
 * The legacy login page, captured: dol_loginfunction() (core/lib/security2.lib.php) prints the
 * complete page with core/tpl/login.tpl.php, its hooks, captcha, 2FA block and OAuth buttons.
 * The form posts to $_SERVER['PHP_SELF'], which app.php sets to the matched route path.
 */
final class LegacyLoginPage
{
	public function __construct(private readonly LegacyContext $ctx)
	{
	}

	public function render(): string
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

		$mysoc = $this->ctx->mysoc();
		$html = '';
		ob_start();
		try {
			dol_loginfunction($this->ctx->langs, $this->ctx->conf, $mysoc ?? '');
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}
}
