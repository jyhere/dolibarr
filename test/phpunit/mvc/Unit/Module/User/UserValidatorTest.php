<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Module\User;

use Dolibarr\Module\User\Service\UserInput;
use Dolibarr\Module\User\Service\UserValidator;
use Dolibarr\Tests\Unit\Module\User\Fixture\ArrayConfig;
use Dolibarr\Tests\Unit\View\Fixture\FakeTranslator;
use PHPUnit\Framework\TestCase;

final class UserValidatorTest extends TestCase
{
	public function testLastnameAndLoginRequired(): void
	{
		$errors = $this->validator()->validate(new UserInput(lastname: '', login: ''), true, 0);

		self::assertSame(['TR:NameNotDefined', 'TR:LoginNotDefined'], $errors);
		self::assertSame([], $this->validator()->validate(new UserInput(lastname: 'Doe', login: 'jdoe'), true, 0));
	}

	public function testQuotaReached(): void
	{
		$validator = $this->validator(limit: 3);
		$input = new UserInput(lastname: 'Doe', login: 'jdoe');

		self::assertSame(['TR:YourQuotaOfUsersIsReached'], $validator->validate($input, true, 3));
		self::assertSame([], $validator->validate($input, true, 2));
		// Only a creation consumes the quota.
		self::assertSame([], $validator->validate($input, false, 3));
		self::assertSame([], $this->validator(limit: 0)->validate($input, true, 99));
	}

	public function testBadLoginChars(): void
	{
		$errors = $this->validator()->validate(new UserInput(lastname: 'Doe', login: 'j@doe'), false, 0);
		self::assertSame(['TR:ErrorBadCharIntoLoginName'], $errors);

		self::assertSame([], $this->validator(badChars: '')->validate(new UserInput(lastname: 'Doe', login: 'j@doe'), false, 0));
	}

	public function testMailRequiredWhenConfigured(): void
	{
		$required = $this->validator(constants: ['USER_MAIL_REQUIRED' => '1']);

		self::assertSame(['TR:ErrorBadEMail'], $required->validate(new UserInput(lastname: 'Doe', login: 'jdoe', email: 'nope'), true, 0));
		self::assertSame(['TR:ErrorBadEMail'], $required->validate(new UserInput(lastname: 'Doe', login: 'jdoe'), true, 0));
		self::assertSame([], $required->validate(new UserInput(lastname: 'Doe', login: 'jdoe', email: 'a@b.fr'), true, 0));
		self::assertSame([], $this->validator()->validate(new UserInput(lastname: 'Doe', login: 'jdoe', email: 'nope'), true, 0));
	}

	public function testSignatureWithTooManyLinksIsRefused(): void
	{
		$input = new UserInput(lastname: 'Doe', login: 'jdoe', signature: 'ErrorTooManyLinksIntoHTMLString');

		self::assertSame(['TR:ErrorTooManyLinksIntoHTMLString'], $this->validator()->validate($input, true, 0));
	}

	/**
	 * @param array<string,string> $constants
	 */
	private function validator(array $constants = [], int $limit = 0, string $badChars = ',@<>"\''): UserValidator
	{
		return new UserValidator(new FakeTranslator(), new ArrayConfig($constants, $limit, $badChars));
	}
}
