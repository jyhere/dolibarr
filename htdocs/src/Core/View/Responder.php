<?php

declare(strict_types=1);

namespace Dolibarr\Core\View;

use Dolibarr\Core\Http\HtmlResponse;
use Dolibarr\Core\Http\JsonResponse;
use Dolibarr\Core\Http\Request;
use Dolibarr\Core\Http\Response;

/**
 * Content negotiation: renders a ViewModel as HTML or JSON.
 *
 * JSON holds only ViewModel::$exposed, converted recursively: Exposable through toArray(),
 * scalars and null as is, arrays item by item. Any other object is refused (LogicException):
 * an object serialised through its public properties would bypass the visibility policy.
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
			foreach ($vm->exposed as $key => $value) {
				$out[$key] = self::export($value, $key);
			}

			return new JsonResponse((object) $out, $vm->status);
		}

		return new HtmlResponse($this->renderer->render($vm->template, $vm->templateData()), $vm->status);
	}

	/**
	 * @throws \LogicException When $value holds an object that is not Exposable
	 */
	private static function export(mixed $value, string $path): mixed
	{
		if ($value instanceof Exposable) {
			$value = $value->toArray();
		}
		if (is_array($value)) {
			$out = [];
			foreach ($value as $key => $item) {
				$out[$key] = self::export($item, $path.'.'.$key);
			}

			return $out;
		}
		if ($value === null || is_scalar($value)) {
			return $value;
		}

		throw new \LogicException('JSON exposure refused for '.$path.': '.get_debug_type($value).' is not '.Exposable::class);
	}
}
