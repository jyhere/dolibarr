<?php

declare(strict_types=1);

namespace Dolibarr\Module\Notes\Controller;

use Dolibarr\Core\CurrentUser;
use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Entity\EntityDefinition;
use Dolibarr\Core\Entity\EntityPermissions;
use Dolibarr\Core\Entity\EntityRegistry;
use Dolibarr\Core\Entity\EntityServices;
use Dolibarr\Core\Entity\EntityTabs;
use Dolibarr\Core\Entity\HasNotes;
use Dolibarr\Core\Entity\LegacyPageSettings;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Http\RedirectResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Legacy\FlashBag;
use Dolibarr\Core\Legacy\HookResult;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Legacy\LegacyObjectLoader;
use Dolibarr\Core\Routing\Route;
use Dolibarr\Core\Routing\UrlGenerator;
use Dolibarr\Core\Security\AccessDeniedException;
use Dolibarr\Core\Security\NotFoundException;
use Dolibarr\Core\Translator;
use Dolibarr\Core\View\ViewModel;
use Dolibarr\Module\Notes\Event\NoteUpdated;
use Dolibarr\Module\Notes\Listener\NoteTriggerException;
use Dolibarr\Module\Notes\Repository\NotesRepository;
use Dolibarr\Module\Notes\View\NotesChrome;

/**
 * The notes tab of any declared entity: the view of htdocs/<element>/note.php (user/note.php for the user)
 * and its actions, core/actions_setnotes.inc.php.
 *
 * The entity's services come from its EntityDefinition through EntityServices (see the EntityDefinition
 * checklist): its EntityRepository returns a HasNotes entity, its EntityPermissions says who reads and edits
 * the notes, its EntityTabs gives the tabs, the banner and the settings of its legacy note page (translation
 * domains, hook contexts, doActions parameters).
 */
final class NotesController
{
	private const ACTIONS = ['setnote_public' => 'note_public', 'setnote_private' => 'note_private'];

	public function __construct(
		private readonly EntityRegistry $registry,
		private readonly EntityServices $services,
		private readonly CurrentUser $me,
		private readonly LegacyHooks $hooks,
		private readonly LegacyObjectLoader $legacy,
		private readonly NotesRepository $notes,
		private readonly NotesChrome $chrome,
		private readonly EventDispatcher $events,
		private readonly Connection $connection,
		private readonly FlashBag $flash,
		private readonly Translator $t,
		private readonly UrlGenerator $urls
	) {
	}

	/**
	 * The notes, in edit mode for one of them with ?action=editnote_public|editnote_private (note.php
	 * l.100-160). doActions runs as in note.php, but a GET never writes: the actions are POSTed to update().
	 * {entity} is the slug of a declared entity type: slugs match [a-z]+ (the route requirement).
	 *
	 * @throws NotFoundException     When the entity type or the entity does not exist
	 * @throws AccessDeniedException When the current user may not read the entity
	 */
	#[Route('/{entity}/{id}/note', methods: ['GET'], name: 'entity_note', requirements: ['entity' => '[a-z]+', 'id' => '\d+'])]
	public function show(Request $r, string $entity, int $id): ViewModel
	{
		[$definition, $found, $perms, $tabs, $settings, $legacyObject] = $this->prepare($entity, $id);
		$action = $this->action($r);
		$doActions = $this->doActions($settings, $legacyObject, $action);

		$permissionNote = $perms->canEditNotes($found);
		$showPrivate = $this->showPrivate();

		$view = new ViewModel('notes:notes', [
			'outputBeforeHeader' => $doActions->output,
			'title' => '',
			'bodyCss' => 'mod-'.$definition->element.' page-card_note',
			'formAction' => $this->urls->generate('entity_note', ['entity' => $definition->slug, 'id' => $id]),
			'action' => $action,
			'permissionNote' => $permissionNote,
			'chrome' => $tabs->chrome($legacyObject, $found, 'note'),
			'fragments' => $this->chrome->fragments($legacyObject, $permissionNote, $action, $showPrivate),
		]);
		$view = $view->expose('notePublic', $found->getPublicNote());

		return $showPrivate ? $view->expose('notePrivate', $found->getPrivateNote()) : $view;
	}

	/**
	 * Actions setnote_public and setnote_private (actions_setnotes.inc.php), then the notes again
	 * (note.php renders them without redirection: a redirect-after-POST here).
	 *
	 * @throws NotFoundException     When the entity type or the entity does not exist
	 * @throws AccessDeniedException When the current user may not read the entity or edit its notes
	 */
	#[Route('/{entity}/{id}/note', methods: ['POST'], name: 'entity_note_post', requirements: ['entity' => '[a-z]+', 'id' => '\d+'])]
	public function update(Request $r, string $entity, int $id): RedirectResponse
	{
		[$definition, $found, $perms, , $settings, $legacyObject] = $this->prepare($entity, $id);
		$back = new RedirectResponse($this->urls->generate('entity_note', ['entity' => $definition->slug, 'id' => $id]));

		// Invalid CSRF token (main.inc.php l.456-478, its warning already set): doActions with an empty action, nothing written
		if ($r->get('errorcode', 'aZ09') === 'InvalidToken') {
			$this->doActions($settings, $legacyObject, '');

			return $back;
		}

		// note.php l.83-90: the standard actions only when doActions returns 0
		$action = $this->action($r);
		if ($this->doActions($settings, $legacyObject, $action)->code !== 0) {
			return $back;
		}
		$field = self::ACTIONS[$action] ?? null;
		if ($field === null || $this->param($r, 'cancel', 'alpha') !== '') {
			return $back;
		}
		if (!$perms->canEditNotes($found)) {
			throw new AccessDeniedException();
		}
		// actions_setnotes.inc.php l.92: the private note is never written by an external user
		if ($field === 'note_private' && !$this->showPrivate()) {
			return $back;
		}

		$posted = $r->getPost($field, 'restricthtml', '');
		$value = dol_html_entity_decode(is_string($posted) ? $posted : '', ENT_QUOTES | ENT_HTML5, 'UTF-8', 1);

		$this->connection->begin();
		try {
			$this->notes->update($definition->tableElement, $id, $field, $value, $this->me->id());
			// update_note() l.4212-4219: the object in memory carries the new note (the trigger reads it)
			$legacyObject->{$field} = $value;
			$this->events->dispatch(new NoteUpdated($definition->element, $definition->tableElement, $id, $field));
			$this->connection->commit();
		} catch (NoteTriggerException $e) {
			$this->connection->rollback();
			foreach ($e->errors as $error) {
				$this->flash->error($error);
			}
		} catch (\Throwable $e) {
			$this->connection->rollback();

			throw $e;
		}

		return $back;
	}

	/**
	 * The definition, the entity readable by the current user, its checker, its tabs, the settings of its
	 * legacy note page (domains loaded and hook contexts initialised here) and its legacy object.
	 *
	 * @return array{EntityDefinition, HasNotes, EntityPermissions, EntityTabs, LegacyPageSettings, \CommonObject}
	 *
	 * @throws NotFoundException
	 * @throws AccessDeniedException
	 * @throws \LogicException When the entity does not implement HasNotes
	 */
	private function prepare(string $slug, int $id): array
	{
		if (!$this->registry->has($slug)) {
			throw new NotFoundException('Unknown entity type '.$slug);
		}
		$definition = $this->registry->get($slug);

		$found = $this->services->repository($definition)->find($id);
		if ($found === null) {
			throw new NotFoundException($slug.' '.$id.' not found');
		}
		if (!$found instanceof HasNotes) {
			throw new \LogicException('The entity '.$slug.' does not implement '.HasNotes::class);
		}

		$perms = $this->services->permissions($definition);
		if (!$perms->canRead($found)) {
			throw new AccessDeniedException();
		}

		$tabs = $this->services->tabs($definition);
		$settings = $tabs->legacyPageSettings('note');
		foreach ($settings->translationDomains as $domain) {
			$this->t->load($domain);
		}
		$this->hooks->init($settings->hookContexts);

		$legacyObject = $this->legacy->byElement($definition, $id);
		if ($legacyObject === null) {
			throw new NotFoundException($slug.' '.$id.' not found');
		}

		return [$definition, $found, $perms, $tabs, $settings, $legacyObject];
	}

	/**
	 * The doActions hook of the legacy note page (user/note.php l.83-87), with its parameters.
	 */
	private function doActions(LegacyPageSettings $settings, \CommonObject $legacyObject, string $action): HookResult
	{
		$object = $legacyObject;
		$result = $this->hooks->execute('doActions', $settings->doActionsParams, $object, $action);
		if ($result->failed()) {
			foreach ($result->errors as $error) {
				$this->flash->error($error);
			}
		}

		return $result;
	}

	/**
	 * notes.tpl.php l.149 and actions_setnotes.inc.php l.92: the private note only for internal users.
	 */
	private function showPrivate(): bool
	{
		return $this->me->companyId() <= 0;
	}

	private function action(Request $r): string
	{
		return $this->param($r, 'action', 'aZ09');
	}

	private function param(Request $r, string $name, string $filter): string
	{
		$value = $r->get($name, $filter);

		return is_string($value) ? $value : '';
	}
}
