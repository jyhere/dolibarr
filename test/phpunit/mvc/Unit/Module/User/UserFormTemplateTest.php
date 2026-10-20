<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Core\View\LatteRenderer;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Tests\Unit\View\Fixture\FakeCurrentUser;
use Dolibarr\Tests\Unit\View\Fixture\FakeTranslator;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Fixture/legacy_token_stub.php';

/**
 * user_form.latte with stub fragments: the data is escaped, the legacy fragments are output raw.
 */
final class UserFormTemplateTest extends TestCase
{
	private const TEMPLATE = __DIR__.'/../../../../../../htdocs/src/Module/User/templates/user_form.latte';

	public function testValuesAreEscapedAndFragmentsRaw(): void
	{
		$html = $this->render(new User(0, 1, 'lo"gin', lastname: '<b>x</b>', job: '"><script>', email: "a'b@c.d"), '/back"><x');

		self::assertStringContainsString('name="lastname" value="&lt;b&gt;x&lt;/b&gt;"', $html);
		self::assertStringContainsString('name="job" value="&quot;&gt;&lt;script&gt;"', $html);
		self::assertStringContainsString('name="login" value="lo&quot;gin"', $html);
		self::assertStringContainsString('name="backtopage" value="/back&quot;&gt;&lt;x"', $html);
		self::assertStringNotContainsString('<script>"', $html);
		self::assertStringNotContainsString('<b>x</b>', $html);
		self::assertStringContainsString('<i data-frag="supervisor"></i>', $html);
		self::assertStringContainsString('<i data-frag="passwordField"></i>', $html);
		self::assertStringContainsString('<input type="hidden" name="token" value="stub-token">', $html);
		self::assertStringContainsString('<form action="/user/new" method="POST" name="createuser">', $html);
	}

	private function render(User $values, string $backtopage): string
	{
		$source = (string) file_get_contents(self::TEMPLATE);
		preg_match_all("/\\\$f\\['(\\w+)'\\]/", $source, $m);
		$f = [];
		foreach ($m[1] as $key) {
			$f[$key] = '<i data-frag="'.$key.'"></i>';
		}
		preg_match_all("/\\\$show\\['(\\w+)'\\]/", $source, $m);
		$show = array_fill_keys($m[1], true);

		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class]), '');
		$renderer = new LatteRenderer(
			sys_get_temp_dir().'/latte-test-'.getmypid(),
			['core' => dirname(self::TEMPLATE, 4).'/Core/templates', 'user' => dirname(self::TEMPLATE)],
			new DolibarrExtension(new FakeTranslator(), new FakeCurrentUser(), $urls),
			true
		);
		$layout = new class () {
			public function header(string $title, string $helpUrl, array $js, array $css, string $bodyCss): string
			{
				return '<!--header-->';
			}

			public function footer(): string
			{
				return '<!--footer-->';
			}
		};

		return $renderer->render('user:user_form', [
			'layout' => $layout,
			'title' => 'T',
			'formAction' => '/user/new',
			'values' => $values,
			'forcePasswordChange' => false,
			'backtopage' => $backtopage,
			'backtopageforcancel' => '',
			'entity' => 1,
			'canSeeSalary' => true,
			'currencySymbol' => '€',
			'show' => $show,
			'titleHtml' => '<i data-frag="title"></i>',
			'loginJs' => '<i data-frag="loginJs"></i>',
			'f' => $f,
		]);
	}
}
