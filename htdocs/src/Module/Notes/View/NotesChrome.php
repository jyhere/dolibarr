<?php

declare(strict_types=1);

namespace Dolibarr\Module\Notes\View;

use Dolibarr\Core\Legacy\LegacyContext;

/**
 * The legacy fragments of core/tpl/notes.tpl.php: the label (editfieldkey) and value (editfieldval) of each
 * note, built with the same calls and editor types. The template itself is not included: it forces the
 * permission of the user element to user->self->write (l.121-122); the caller gives the permission instead.
 */
final class NotesChrome
{
	private const PUBLIC = 'note_public';
	private const PRIVATE = 'note_private';

	public function __construct(private readonly LegacyContext $ctx)
	{
	}

	/**
	 * notes.tpl.php l.45-164. The edit links and forms point to $_SERVER['PHP_SELF'] (the route path).
	 *
	 * @param string $action      The action of the page: editnote_public / editnote_private show that note's editor
	 * @param bool   $showPrivate Whether the private note is shown (empty($user->socid), l.149): null fragments otherwise
	 * @return array{publicKey: string, publicVal: string, privateKey: ?string, privateVal: ?string}
	 */
	public function fragments(\CommonObject $object, bool $permissionnote, string $action, bool $showPrivate): array
	{
		$form = $this->ctx->form();
		$permission = $permissionnote ? 1 : 0;
		$moreparam = '';

		$publicType = $this->editorType('FCKEDITOR_ENABLE_NOTE_PUBLIC');
		$publicValue = $this->value((string) $object->note_public, 'MAIN_AUTO_TIMESTAMP_IN_PUBLIC_NOTES', $action === 'edit'.self::PUBLIC);

		$fragments = [
			'publicKey' => $form->editfieldkey('NotePublic', self::PUBLIC, $publicValue, $object, $permission, $publicType, $moreparam, 0, 0),
			'publicVal' => $form->editfieldval('NotePublic', self::PUBLIC, $publicValue, $object, $permission, $publicType, '', null, null, $moreparam, 1, '', 'id', 'auto', [], $action),
			'privateKey' => null,
			'privateVal' => null,
		];
		if ($showPrivate) {
			$privateType = $this->editorType('FCKEDITOR_ENABLE_NOTE_PRIVATE');
			$privateValue = $this->value((string) $object->note_private, 'MAIN_AUTO_TIMESTAMP_IN_PRIVATE_NOTES', $action === 'edit'.self::PRIVATE);
			$fragments['privateKey'] = $form->editfieldkey('NotePrivate', self::PRIVATE, $privateValue, $object, $permission, $privateType, $moreparam, 0, 0);
			$fragments['privateVal'] = $form->editfieldval('NotePrivate', self::PRIVATE, $privateValue, $object, $permission, $privateType, '', null, null, $moreparam, 1, '', 'id', 'auto', [], $action);
		}

		return $fragments;
	}

	/**
	 * notes.tpl.php l.137-146.
	 */
	private function editorType(string $constant): string
	{
		return isModEnabled('fckeditor') && getDolGlobalString($constant) ? 'ckeditor:dolibarr_notes:100%:200::1:12:95%:0' : 'textarea:12:95%';
	}

	/**
	 * notes.tpl.php l.63-83: in edit mode, MAIN_AUTO_TIMESTAMP_IN_*_NOTES appends "-- <date> <user> --".
	 */
	private function value(string $value, string $constant, bool $editMode): string
	{
		if (!$editMode || !getDolGlobalString($constant)) {
			return $value;
		}
		$user = $this->ctx->user;
		$stringtoadd = dol_print_date(dol_now(), 'dayhour').' '.($user === null ? '' : $user->getFullName($this->ctx->langs)).' --';
		$value = dol_concatdesc($value, ($value ? "\n" : '').'-- '.$stringtoadd);

		return $value.(dol_textishtml($value) ? "<br>\n" : "\n");
	}
}
