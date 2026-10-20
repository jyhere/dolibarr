<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Service;

use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Module\User\Entity\User;
use Dolibarr\Module\User\Event\UserCreated;
use Dolibarr\Module\User\Event\UserDeleted;
use Dolibarr\Module\User\Event\UserGroupsChanged;
use Dolibarr\Module\User\Event\UserPasswordChanged;
use Dolibarr\Module\User\Event\UserRightsChanged;
use Dolibarr\Module\User\Event\UserStatusChanged;
use Dolibarr\Module\User\Event\UserUpdated;
use Dolibarr\Module\User\Repository\UserRepository;
use Dolibarr\Module\User\Repository\UserWriter;

/**
 * Use cases on users. Each one runs in a transaction: the writes, then the event (the legacy
 * triggers have already run in the writer, hence legacyTriggerFired), then the commit. Any
 * exception, from a write or a listener, rolls everything back and is rethrown.
 * Permissions are checked by the callers (UserPermissionChecker).
 */
final class UserService
{
	public function __construct(
		private readonly UserRepository $repo,
		private readonly UserWriter $writer,
		private readonly EventDispatcher $events,
		private readonly Connection $db,
		private readonly CurrentUser $me
	) {
	}

	/**
	 * user/card.php l.442-475.
	 *
	 * @param list<int> $categoryIds
	 * @throws ValidationException
	 */
	public function create(User $u, ?string $password, array $categoryIds): User
	{
		return $this->transactional(function () use ($u, $password, $categoryIds): User {
			$id = $this->writer->insert($u, $this->me->id());
			if ($password !== null && $password !== '') {
				$this->writer->setPassword($id, $password, false, $this->me->id());
			}
			if ($categoryIds !== []) {
				$this->writer->setCategories($id, $categoryIds);
			}
			$created = $this->load($id);
			$this->events->dispatch(new UserCreated($created));

			return $created;
		});
	}

	/**
	 * user/card.php l.497-745: update, contact link when posted, tags (always replaced).
	 *
	 * $password, when given and not the current one, is set in the same transaction: on the card update()
	 * calls setPassword() itself (user.class.php l.2474-2481) and a refused password cancels the whole update.
	 * The writer does not copy the password, hence the explicit call; setPassword() resets force_pass_change.
	 *
	 * @param list<int> $categoryIds
	 * @param ?int      $contactId   null when the contact field was not posted
	 * @param ?string   $password    The clear password posted, null or '' to keep it
	 * @throws ValidationException
	 * @throws NotFoundException
	 */
	public function update(User $u, array $categoryIds, ?int $contactId, ?int $companyId, ?string $password = null): User
	{
		return $this->transactional(function () use ($u, $categoryIds, $contactId, $companyId, $password): User {
			$before = $this->load($u->id);
			$this->writer->update($u, $this->me->id());
			if ($password !== null && $password !== '' && !$this->writer->isCurrentPassword($u->id, $password)) {
				$this->writer->setPassword($u->id, $password, false, $this->me->id());
				$this->events->dispatch(new UserPasswordChanged($u->id, false));
			}
			if ($contactId !== null) {
				$this->writer->linkToContact($u->id, $contactId, $companyId ?? 0);
			}
			$this->writer->setCategories($u->id, $categoryIds);
			$after = $this->load($u->id);
			$this->events->dispatch(new UserUpdated($before, $after));

			return $after;
		});
	}

	/**
	 * @throws ValidationException
	 * @throws NotFoundException
	 */
	public function setStatus(int $id, bool $enabled): User
	{
		return $this->transactional(function () use ($id, $enabled): User {
			$this->load($id);
			$this->writer->setStatus($id, $enabled);
			$user = $this->load($id);
			$this->events->dispatch(new UserStatusChanged($user, $enabled));

			return $user;
		});
	}

	/**
	 * @throws ValidationException
	 * @throws NotFoundException
	 */
	public function delete(int $id): void
	{
		$this->transactional(function () use ($id): null {
			$user = $this->load($id);
			$this->writer->delete($id, $this->me->id());
			$this->events->dispatch(new UserDeleted($id, $user->login));

			return null;
		});
	}

	/**
	 * user/card.php l.776-797: a new generated password, sent by email when $send.
	 * The email goes after the commit, so a rolled back password is never sent; a send failure
	 * then throws ValidationException without reverting the password (as card.php l.788-791).
	 *
	 * @return string The new clear password (shown by the caller when not sent, as PasswordChangedTo)
	 * @throws ValidationException
	 */
	public function resetPassword(int $id, bool $send): string
	{
		$clear = $this->transactional(function () use ($id, $send): string {
			$clear = $this->writer->setPassword($id, '', false, $this->me->id());
			$this->events->dispatch(new UserPasswordChanged($id, $send));

			return $clear;
		});
		if ($send) {
			$this->writer->sendPassword($id, $clear);
		}

		return $clear;
	}

	/**
	 * Whether $clear is the stored password of the user ($passwordismodified of card.php l.666-671, inverted).
	 *
	 * @throws ValidationException When the user does not exist
	 */
	public function isCurrentPassword(int $id, string $clear): bool
	{
		return $this->writer->isCurrentPassword($id, $clear);
	}

	/**
	 * user/card.php l.757-772: the password typed in the form, set even when it is the current one.
	 *
	 * @throws ValidationException
	 */
	public function setPasswordFromForm(int $id, string $clear): void
	{
		$this->transactional(function () use ($id, $clear): null {
			$this->writer->setPassword($id, $clear, false, $this->me->id());
			$this->events->dispatch(new UserPasswordChanged($id, false));

			return null;
		});
	}

	/**
	 * user/perms.php l.114-128, in the current entity.
	 *
	 * @throws ValidationException
	 */
	public function addRights(int $id, ?int $rightId, ?string $module): void
	{
		$this->transactional(function () use ($id, $rightId, $module): null {
			$this->writer->addRights($id, $rightId, $module, 0);
			$this->events->dispatch(new UserRightsChanged($id));

			return null;
		});
		$this->reloadIfSelf($id);
	}

	/**
	 * user/perms.php l.142-156, in the current entity.
	 *
	 * @throws ValidationException
	 */
	public function delRights(int $id, ?int $rightId, ?string $module): void
	{
		$this->transactional(function () use ($id, $rightId, $module): null {
			$this->writer->delRights($id, $rightId, $module, 0);
			$this->events->dispatch(new UserRightsChanged($id));

			return null;
		});
		$this->reloadIfSelf($id);
	}

	/**
	 * user/card.php l.477-497, in the entity of the group.
	 *
	 * @throws ValidationException
	 * @throws NotFoundException
	 */
	public function addToGroup(int $id, int $groupId): void
	{
		$this->transactional(function () use ($id, $groupId): null {
			$this->writer->setInGroup($id, $groupId, $this->groupEntity($groupId));
			$this->events->dispatch(new UserGroupsChanged($id));

			return null;
		});
	}

	/**
	 * @throws ValidationException
	 * @throws NotFoundException
	 */
	public function removeFromGroup(int $id, int $groupId): void
	{
		$this->transactional(function () use ($id, $groupId): null {
			$this->writer->removeFromGroup($id, $groupId, $this->groupEntity($groupId));
			$this->events->dispatch(new UserGroupsChanged($id));

			return null;
		});
	}

	/**
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	private function transactional(callable $work): mixed
	{
		$this->db->begin();
		try {
			$result = $work();
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
		$this->db->commit();

		return $result;
	}

	/**
	 * @throws NotFoundException
	 */
	private function load(int $id): User
	{
		$user = $this->repo->find($id);
		if ($user === null) {
			throw new NotFoundException('User '.$id.' not found');
		}

		return $user;
	}

	/**
	 * card.php l.481-483 passes $editgroup->entity, read here without loading the group.
	 *
	 * @throws NotFoundException When the group does not exist
	 */
	private function groupEntity(int $groupId): int
	{
		$row = $this->db->fetchOne('SELECT entity FROM '.$this->db->prefix().'usergroup WHERE rowid = '.$groupId);
		$entity = $row === null ? null : (get_object_vars($row)['entity'] ?? null);
		if (!is_numeric($entity)) {
			throw new NotFoundException('Group '.$groupId.' not found');
		}

		return (int) $entity;
	}

	/**
	 * perms.php l.123-128: one's own rights (and menu) are reloaded after a change on oneself.
	 */
	private function reloadIfSelf(int $id): void
	{
		if ($id === $this->me->id()) {
			$this->writer->reloadOwnRights();
		}
	}
}
