<?php

declare(strict_types=1);

namespace Dolibarr\Module\Documents\View;

use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Module\Documents\Service\DocumentsTarget;

/**
 * The legacy fragments of core/tpl/document_actions_post_headers.tpl.php l.62-262, built with the same calls and
 * arguments: the confirmation popup of a deletion, the forms to upload a file and to add a link
 * (form_attach_new_file()), the list of the files (list_of_documents()) and the list of the links (listOfLinks()).
 * The template itself is not included: the page structure around the fragments is documents.latte.
 *
 * The forms and links of the FormFile helpers point to $_SERVER['PHP_SELF'], the route path of the page. As the
 * template, the confirmation and the list of links read urlfile and linkid from the request (GETPOST()).
 */
final class DocumentsChrome
{
	/** document_actions_post_headers.tpl.php l.82-85: the drag and drop of the files is enabled for these module parts */
	private const MOVABLE_MODULE_PARTS = ['product', 'produit', 'societe', 'user', 'ticket', 'holiday', 'expensereport'];

	/** l.114-145: the upload form suggests the ref of the object as prefix of the file names for these module parts */
	private const REF_PREFIXED_MODULE_PARTS = [
		'facture_fournisseur', 'commande_fournisseur', 'facture', 'commande', 'propal', 'payment', 'supplier_proposal',
		'ficheinter', 'contract', 'expedition', 'project', 'project_task', 'expensereport', 'salaries', 'tax', 'tax-vat',
		'produit', 'product_batch', 'bom', 'mrp',
	];

	public function __construct(private readonly LegacyContext $ctx, private readonly LegacyHooks $hooks)
	{
	}

	/**
	 * @param string $action    The action of the page: deletefile / deletelink show the confirmation popup, update the
	 *                          edition of a link, editfile (read by list_of_documents()) the renaming of a file
	 * @param string $sortfield The sort field of the list of files (list_of_documents() and listOfLinks() read the globals)
	 * @param string $sortorder The sort order of the list of files
	 * @param string $callerMask The $savingdocmask of the caller of the legacy template (EntityDocuments::savingDocMask()),
	 *                          '' for none
	 * @return array{confirm: string, attachForms: array{upload: string, link: string}, documents: string, links: string}
	 */
	public function fragments(DocumentsTarget $target, string $action, string $sortfield, string $sortorder, string $callerMask = ''): array
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';

		$langs = $this->ctx->langs;
		$conf = $this->ctx->conf;
		$form = $this->ctx->form();
		$object = $target->legacyObject;
		$modulepart = $target->modulePart;
		// The legacy page passes $permissiontoadd, a boolean: dolGetButtonTitle() compares it with 2 (true == 2 selects the button)
		$permission = $target->canWrite;
		$param = '&id='.$object->id;

		// l.62
		$langs->load('link');

		// l.82-91
		$disablemove = in_array($modulepart, self::MOVABLE_MODULE_PARTS, true) ? 0 : 1;
		$hookAction = '';
		$hookResult = $this->hooks->execute('isLinkedDocumentObjectNotMovable', [], $object, $hookAction);
		if ($hookResult->code !== 0) {
			$disablemove = $hookResult->array['disablemove'] ?? null;
		}
		$confirm = $hookResult->output;

		// l.97-108: the confirmation of a deletion, its Yes button posting to the page (ruling R4)
		if ($action === 'deletefile' || $action === 'deletelink') {
			$langs->load('companies');
			$confirm .= $this->postingConfirm(
				$form,
				$_SERVER['PHP_SELF'].'?id='.$object->id.'&urlfile='.urlencode(self::stringOf(GETPOST('urlfile'))).'&linkid='.GETPOSTINT('linkid').$param,
				$langs->trans('DeleteFile'),
				$langs->trans('ConfirmDeleteFile')
			);
		}

		$formfile = new \FormFile($this->ctx->db);

		// l.155-174: the forms to upload a file and to add a link, returned (last argument 2)
		$attachForms = $formfile->form_attach_new_file(
			$_SERVER['PHP_SELF'].'?id='.$object->id,
			'',
			0,
			0,
			$permission,
			($conf->browser->layout ?? '') === 'phone' ? 40 : 60,
			$object,
			'',
			1,
			$this->savingDocMask($modulepart, $object, $callerMask),
			1,
			'formuserfile',
			'',
			'',
			0,
			0,
			0,
			2
		);
		$formToUploadAFile = is_array($attachForms) ? (string) ($attachForms['formToUploadAFile'] ?? '') : '';
		$formToAddALink = is_array($attachForms) ? (string) ($attachForms['formToAddALink'] ?? '') : '';

		$fileArray = dol_dir_list($target->directory, 'files', 0, '', '(\.meta|_preview.*\.png)$', $sortfield, strtolower($sortorder) === 'desc' ? SORT_DESC : SORT_ASC, 1);

		$documents = $this->withListGlobals($modulepart, $sortfield, $sortorder, static function () use ($formfile, $fileArray, $object, $modulepart, $param, $permission, $target, $sortfield, $sortorder, $disablemove, $formToUploadAFile): void {
			// l.193-222
			$formfile->list_of_documents(
				$fileArray,
				$object,
				$modulepart,
				$param,
				0,
				'',
				$permission,
				0,
				'',
				0,
				'',
				'',
				0,
				$permission,
				$target->directory,
				$sortfield,
				$sortorder,
				$disablemove,
				0,
				-1,
				'',
				['afteruploadtitle' => $formToUploadAFile, 'showhideaddbutton' => getDolGlobalInt('MAIN_DOCUMENTS_SHOW_FILE_ATTACHMENT_FORM') ? 0 : 1]
			);
		});

		$links = $this->withListGlobals($modulepart, $sortfield, $sortorder, static function () use ($formfile, $object, $permission, $action, $param, $formToAddALink): void {
			// l.231-263
			$formfile->listOfLinks(
				$object,
				$permission,
				$action,
				(string) GETPOSTINT('linkid'),
				$param,
				'formaddlink',
				['afterlinktitle' => $formToAddALink, 'showhideaddbutton' => getDolGlobalInt('MAIN_DOCUMENTS_SHOW_FILE_LINKING_FORM') ? 0 : 1]
			);
		});

		return [
			'confirm' => $confirm,
			'attachForms' => ['upload' => $formToUploadAFile, 'link' => $formToAddALink],
			'documents' => self::withTokenOnRenameLinks($documents),
			'links' => $links,
		];
	}

	/**
	 * l.122-150: a $savingdocmask given by the caller wins; else the ref of the object as prefix for some module
	 * parts. MAIN_DISABLE_SUGGEST_REF_AS_PREFIX forces none.
	 */
	private function savingDocMask(string $modulepart, \CommonObject $object, string $callerMask): string
	{
		if (getDolGlobalString('MAIN_DISABLE_SUGGEST_REF_AS_PREFIX')) {
			return '';
		}
		if ($callerMask !== '') {
			return $callerMask;
		}
		if (!in_array($modulepart, self::REF_PREFIXED_MODULE_PARTS, true)) {
			return '';
		}

		return dol_sanitizeFileName((string) $object->ref).'-__file__';
	}

	/**
	 * Form::formconfirm(..., useajax 1) of l.99-107, its Yes button posting instead of jumping with a GET (ruling R4):
	 * MAIN_MAX_URL_LENGTH_FOR_GET is 1 during the call, so dolSubmitConfirmForm() always submits a POST form (action,
	 * confirm, urlfile and linkid in the query string, the token in the body). The constant is restored afterwards.
	 */
	private function postingConfirm(\Form $form, string $page, string $title, string $question): string
	{
		$global = $this->ctx->conf->global;
		$hadValue = property_exists($global, 'MAIN_MAX_URL_LENGTH_FOR_GET');
		$previous = $hadValue ? $global->MAIN_MAX_URL_LENGTH_FOR_GET : null;
		$global->MAIN_MAX_URL_LENGTH_FOR_GET = '1';
		try {
			return $form->formconfirm($page, $title, $question, 'confirm_deletefile', '', '', 1);
		} finally {
			if ($hadValue) {
				$global->MAIN_MAX_URL_LENGTH_FOR_GET = $previous;
			} else {
				unset($global->MAIN_MAX_URL_LENGTH_FOR_GET);
			}
		}
	}

	/**
	 * Runs a FormFile list and returns what it printed. list_of_documents() and listOfLinks() read the globals
	 * $sortfield and $sortorder, completeFileArrayWithDatabaseInfo() the global $modulepart, as set by the legacy page:
	 * they are set for the call and restored afterwards.
	 *
	 * @param \Closure(): void $print
	 */
	private function withListGlobals(string $modulepart, string $sortfield, string $sortorder, \Closure $print): string
	{
		$names = ['modulepart' => $modulepart, 'sortfield' => $sortfield, 'sortorder' => $sortorder];
		$saved = [];
		foreach ($names as $name => $value) {
			$saved[$name] = array_key_exists($name, $GLOBALS) ? [$GLOBALS[$name]] : [];
			$GLOBALS[$name] = $value;
		}
		ob_start();
		try {
			$print();
		} finally {
			$output = (string) ob_get_clean();
			foreach ($saved as $name => $previous) {
				if ($previous === []) {
					unset($GLOBALS[$name]);
				} else {
					$GLOBALS[$name] = $previous[0];
				}
			}
		}

		return $output;
	}

	/**
	 * app.php defines CSRFCHECK_WITH_TOKEN: main.inc.php refuses any request with an action and no token. The other
	 * action links of the lists carry the token; the rename link of list_of_documents() (?action=editfile) does not,
	 * it gets it here.
	 */
	private static function withTokenOnRenameLinks(string $html): string
	{
		return (string) preg_replace_callback(
			'#href="([^"]*[?&]action=editfile(?:&|&amp;)[^"]*)"#',
			static fn (array $matches): string => preg_match('#[?&]token=#', $matches[1]) === 1
				? $matches[0]
				: 'href="'.$matches[1].'&token='.urlencode(newToken()).'"',
			$html
		);
	}

	private static function stringOf(mixed $value): string
	{
		return is_string($value) ? $value : '';
	}
}
