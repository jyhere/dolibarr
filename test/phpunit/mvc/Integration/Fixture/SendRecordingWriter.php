<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Fixture;

use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Repository\UserWriter;
use Dolibarr\Module\User\Service\ValidationException;

/**
 * A UserWriter delegating to the wrapped one, except sendPassword(): recorded, then refused with
 * $sendErrors when given (no mailer in the test instances).
 */
final class SendRecordingWriter implements UserWriter
{
	/** @var list<array{int, string}> The (id, clear password) of each sendPassword() call */
	public array $sent = [];

	/**
	 * @param list<string> $sendErrors
	 */
	public function __construct(private readonly UserWriter $inner, private readonly array $sendErrors = [])
	{
	}

	public function insert(User $user, int $creatorId): int
	{
		return $this->inner->insert($user, $creatorId);
	}

	public function update(User $user, int $actorId): void
	{
		$this->inner->update($user, $actorId);
	}

	public function isCurrentPassword(int $id, string $clearPassword): bool
	{
		return $this->inner->isCurrentPassword($id, $clearPassword);
	}

	public function setStatus(int $id, bool $enabled): void
	{
		$this->inner->setStatus($id, $enabled);
	}

	public function delete(int $id, int $actorId): void
	{
		$this->inner->delete($id, $actorId);
	}

	public function setPassword(int $id, string $clearPassword, bool $changeLater, int $actorId): string
	{
		return $this->inner->setPassword($id, $clearPassword, $changeLater, $actorId);
	}

	public function sendPassword(int $id, string $clearPassword): void
	{
		$this->sent[] = [$id, $clearPassword];
		if ($this->sendErrors !== []) {
			throw new ValidationException($this->sendErrors);
		}
	}

	public function addRights(int $id, ?int $rightId, ?string $module, int $entity): void
	{
		$this->inner->addRights($id, $rightId, $module, $entity);
	}

	public function delRights(int $id, ?int $rightId, ?string $module, int $entity): void
	{
		$this->inner->delRights($id, $rightId, $module, $entity);
	}

	public function setInGroup(int $id, int $groupId, int $entity): void
	{
		$this->inner->setInGroup($id, $groupId, $entity);
	}

	public function removeFromGroup(int $id, int $groupId, int $entity): void
	{
		$this->inner->removeFromGroup($id, $groupId, $entity);
	}

	public function setCategories(int $id, array $categoryIds): void
	{
		$this->inner->setCategories($id, $categoryIds);
	}

	public function linkToContact(int $id, int $contactId, int $companyId): bool
	{
		return $this->inner->linkToContact($id, $contactId, $companyId);
	}

	public function reloadOwnRights(): void
	{
		$this->inner->reloadOwnRights();
	}

	public function saveListFields(string $varpage, string $fields): void
	{
		$this->inner->saveListFields($varpage, $fields);
	}
}
