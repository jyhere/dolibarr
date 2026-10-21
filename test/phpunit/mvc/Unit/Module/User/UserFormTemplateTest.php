<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Core\Routing\RouteCompiler;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\View\DolibarrExtension;
use Dolibarr\Core\View\LatteRenderer;
use Dolibarr\Module\User\Controller\UserController;
use Dolibarr\Module\User\Controller\UserListController;
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

	public function testEditFormEscapesValuesInInputsAndHiddenInputs(): void
	{
		$values = new User(7, 1, 'lo"gin', lastname: '<b>x</b>', job: '"><script>', email: "a'b@c.d", officePhone: '<i>1</i>');

		$html = $this->render($values, '', 'edit', ['canEdit' => true, 'canEditLogin' => true, 'canEditEmail' => true, 'ldap' => false]);
		self::assertStringContainsString('<form action="/user/7/edit" method="POST" name="updateuser" enctype="multipart/form-data">', $html);
		self::assertStringContainsString('<input type="hidden" name="action" value="update">', $html);
		self::assertStringContainsString('name="lastname" value="&lt;b&gt;x&lt;/b&gt;"', $html);
		self::assertStringContainsString('name="login" value="lo&quot;gin"', $html);
		self::assertStringContainsString('name="job" value="&quot;&gt;&lt;script&gt;"', $html);
		self::assertStringContainsString('name="save"', $html);
		self::assertStringContainsString('name="cancel"', $html);
		self::assertStringNotContainsString('name="createuser"', $html);

		// Read-only (password-only actor): hidden inputs and texts, escaped too
		$html = $this->render($values, '', 'edit', ['canEdit' => false, 'canEditLogin' => false, 'canEditEmail' => true, 'ldap' => false]);
		self::assertStringContainsString('<input type="hidden" name="lastname" value="&lt;b&gt;x&lt;/b&gt;">&lt;b&gt;x&lt;/b&gt;', $html);
		self::assertStringContainsString('<input type="hidden" name="login" value="lo&quot;gin">lo"gin', $html);
		self::assertStringContainsString('<input type="hidden" name="office_phone" value="&lt;i&gt;1&lt;/i&gt;">&lt;i&gt;1&lt;/i&gt;', $html);
		self::assertStringContainsString('<input type="hidden" name="job" value="&quot;&gt;&lt;script&gt;">"&gt;&lt;script&gt;', $html);
		self::assertStringNotContainsString('<b>x</b>', $html);
		self::assertStringNotContainsString('<i>1</i>', $html);
	}

	public function testEmailIsPlainTextWhenCredentialsAreProtected(): void
	{
		$values = new User(7, 1, 'login', lastname: 'x', email: 'a"<b>@c.d');

		$html = $this->render($values, '', 'edit', ['canEdit' => true, 'canEditLogin' => false, 'canEditEmail' => false, 'ldap' => false]);
		self::assertStringContainsString('a"&lt;b&gt;@c.d</td>', $html);
		self::assertStringNotContainsString('name="email"', $html);

		$html = $this->render($values, '', 'edit', ['canEdit' => true, 'canEditLogin' => true, 'canEditEmail' => true, 'ldap' => false]);
		self::assertStringContainsString('type="text" name="email" value="a&quot;&lt;b&gt;@c.d"', $html);
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function render(User $values, string $backtopage, string $mode = 'create', array $extra = []): string
	{
		$source = (string) file_get_contents(self::TEMPLATE);
		preg_match_all("/\\\$f\\['(\\w+)'\\]/", $source, $m);
		$f = [];
		foreach ($m[1] as $key) {
			$f[$key] = '<i data-frag="'.$key.'"></i>';
		}
		preg_match_all("/\\\$show\\['(\\w+)'\\]/", $source, $m);
		$show = array_fill_keys($m[1], true);

		$urls = new UrlGenerator((new RouteCompiler())->compile([UserController::class, UserListController::class]), '');
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
			'mode' => $mode,
			'formAction' => $mode === 'edit' ? '/user/'.$values->id.'/edit' : '/user/new',
			'employmentDash' => false,
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
		] + $extra);
	}
}
