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
 * Use cases on users. Each one runs in a transaction (Connection::transactional()): the writes, then
 * the event (the legacy triggers have already run in the writer, hence legacyTriggerFired), then the
 * commit. Any exception, from a write or a listener, rolls everything back and is rethrown; a failed
 * ROLLBACK throws DatabaseException with that exception as its previous one.
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
	 * @param list<int>|null $categoryIds The tags, null when the form does not show them
	 * @throws ValidationException
	 */
	public function create(User $user, ?string $password, ?array $categoryIds): User
	{
		return $this->db->transactional(function () use ($user, $password, $categoryIds): User {
			$id = $this->writer->insert($user, $this->me->id());
			if ($password !== null && $password !== '') {
				$this->writer->setPassword($id, $password, false, $this->me->id());
			}
			if ($categoryIds !== null && $categoryIds !== []) {
				$this->writer->setCategories($id, $categoryIds);
			}
			$created = $this->load($id);
			$this->events->dispatch(new UserCreated($created));

			return $created;
		});
	}

	/**
	 * user/card.php l.497-745: update, contact link when posted, tags replaced when the form shows them (card.php
	 * always replaces them, wiping them when the row is hidden; null here leaves them).
	 *
	 * $password, when given and not the current one, is set in the same transaction: on the card update()
	 * calls setPassword() itself (user.class.php l.2474-2481) and a refused password cancels the whole update.
	 * The writer does not copy the password, hence the explicit call; setPassword() resets force_pass_change.
	 *
	 * @param list<int>|null $categoryIds null when the tags row is not shown: left as they are
	 * @param ?int           $contactId   null when the contact field was not posted
	 * @param ?string   $password    The clear password posted, null or '' to keep it
	 * @throws ValidationException
	 * @throws NotFoundException
	 */
	public function update(User $user, ?array $categoryIds, ?int $contactId, ?int $companyId, ?string $password = null): UserUpdateResult
	{
		return $this->db->transactional(function () use ($user, $categoryIds, $contactId, $companyId, $password): UserUpdateResult {
			$before = $this->load($user->id);
			// card.php l.666-671 ($passwordismodified), tested before update() as there
			$passwordChanged = $password !== null && $password !== '' && !$this->writer->isCurrentPassword($user->id, $password);
			$this->writer->update($user, $this->me->id());
			if ($passwordChanged) {
				$this->writer->setPassword($user->id, (string) $password, false, $this->me->id());
				$this->events->dispatch(new UserPasswordChanged($user->id, false));
			}
			$mismatch = $contactId !== null && $this->writer->linkToContact($user->id, $contactId, $companyId ?? 0);
			if ($categoryIds !== null) {
				$this->writer->setCategories($user->id, $categoryIds);
			}
			$after = $this->load($user->id);
			$this->events->dispatch(new UserUpdated($before, $after));

			return new UserUpdateResult($after, $passwordChanged, $mismatch);
		});
	}

	/**
	 * @throws ValidationException
	 * @throws NotFoundException
	 */
	public function setStatus(int $id, bool $enabled): User
	{
		return $this->db->transactional(function () use ($id, $enabled): User {
			$this->load($id);
			$this->writer->setStatus($id, $enabled);
			$user = $this->load($id);
			$this->events->dispatch(new UserStatusChanged($user, $enabled));

			return $user;
		});
	}

	/**
	 * Mass actions disable and reactivate of user/list.php l.318-372: the status of several users in one
	 * transaction. A user already in that status is left as is (\User::setstatus() returns 0) and not counted.
	 * With a quota ($limitUsers > 0), the active users counted after the change must stay under it (l.356-362,
	 * checked on a disabling too, as there), else nothing is written.
	 * The refusals of list.php (oneself, an admin) are the caller's, before the call.
	 *
	 * @param list<int> $ids
	 * @return int The number of users changed (RecordsModified)
	 * @throws ValidationException
	 * @throws NotFoundException
	 * @throws QuotaReachedException
	 */
	public function setStatuses(array $ids, bool $enabled, int $limitUsers): int
	{
		return $this->db->transactional(function () use ($ids, $enabled, $limitUsers): int {
			$status = $enabled ? 1 : 0;
			$toChange = [];
			foreach ($ids as $id) {
				if ($this->load($id)->status !== $status) {
					$toChange[$id] = $id;
				}
			}
			// The count list.php makes after the writes (getNbOfUsers('active') of the users of the entity, the
			// ones find() loads), computed before them: nothing is written when the quota is reached.
			if ($limitUsers > 0 && $this->repo->countForQuota() + ($enabled ? 1 : -1) * count($toChange) >= $limitUsers) {
				throw new QuotaReachedException('Users quota reached');
			}
			foreach ($toChange as $id) {
				$this->writer->setStatus($id, $enabled);
				$this->events->dispatch(new UserStatusChanged($this->load($id), $enabled));
			}

			return count($toChange);
		});
	}

	/**
	 * The columns of a list chosen by the current user (user/list.php l.274, actions_changeselectedfields.inc.php).
	 *
	 * @throws ValidationException
	 */
	public function saveListFields(string $varpage, string $fields): void
	{
		$this->writer->saveListFields($varpage, $fields);
	}

	/**
	 * @throws ValidationException
	 * @throws NotFoundException
	 */
	public function delete(int $id): void
	{
		$this->db->transactional(function () use ($id): null {
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
		$clear = $this->db->transactional(function () use ($id, $send): string {
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
	 * user/card.php l.757-772: the password typed in the form, set even when it is the current one.
	 *
	 * @throws ValidationException
	 */
	public function setPasswordFromForm(int $id, string $clear): void
	{
		$this->db->transactional(function () use ($id, $clear): null {
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
		$this->db->transactional(function () use ($id, $rightId, $module): null {
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
		$this->db->transactional(function () use ($id, $rightId, $module): null {
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
		$this->db->transactional(function () use ($id, $groupId): null {
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
		$this->db->transactional(function () use ($id, $groupId): null {
			$this->writer->removeFromGroup($id, $groupId, $this->groupEntity($groupId));
			$this->events->dispatch(new UserGroupsChanged($id));

			return null;
		});
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
		$entity = $this->db->fetchValue('SELECT entity FROM @usergroup WHERE rowid = :id', ['id' => $groupId]);
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
