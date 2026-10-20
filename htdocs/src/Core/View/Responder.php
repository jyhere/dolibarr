<?php

declare(strict_types=1);

namespace Dolibarr\Core\View;

use Dolibarr\Core\Http\HtmlResponse;
use Dolibarr\Core\Http\JsonResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\Response;

/**
 * Content negotiation: renders a ViewModel as HTML or JSON.
 */
final class Responder
{
	public function __construct(private readonly LatteRenderer $renderer, private readonly Request $request)
	{
	}

	public function respond(ViewModel $vm): Response
	{
		if ($this->request->wantsJson()) {
			$out = [];
			foreach ($vm->data as $key => $value) {
				if ($value instanceof Exposable) {
					$out[$key] = $value->toArray();
				} elseif ($value === null || is_scalar($value) || is_array($value)) {
					$out[$key] = $value;
				}
			}

			return new JsonResponse($out, $vm->status);
		}

		return new HtmlResponse($this->renderer->render($vm->template, $vm->data), $vm->status);
	}
}
