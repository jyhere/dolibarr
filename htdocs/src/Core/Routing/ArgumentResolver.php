<?php

declare(strict_types=1);

namespace Dolibarr\Core\Routing;

use Dolibarr\Core\Http\Request;

/**
 * Resolves the arguments of a controller action from its parameter list.
 *
 * A parameter typed Request receives the request; a parameter named after a
 * route variable receives the captured value cast to its declared scalar type;
 * any other parameter receives its default value, or null when nullable.
 */
final class ArgumentResolver
{
	/**
	 * @return list<mixed>
	 * @throws \LogicException When a parameter cannot be resolved
	 */
	public function resolve(\ReflectionMethod $method, MatchedRoute $matched, Request $request): array
	{
		$args = [];
		foreach ($method->getParameters() as $parameter) {
			$type = $parameter->getType();
			$typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
			$name = $parameter->getName();
			if ($typeName === Request::class) {
				$args[] = $request;
			} elseif (array_key_exists($name, $matched->params)) {
				$args[] = $this->cast($matched->params[$name], $typeName);
			} elseif ($parameter->isDefaultValueAvailable()) {
				$args[] = $parameter->getDefaultValue();
			} elseif ($parameter->allowsNull()) {
				$args[] = null;
			} else {
				throw new \LogicException('Cannot resolve parameter $'.$name.' of '.$method->class.'::'.$method->getName().'()');
			}
		}

		return $args;
	}

	private function cast(string $value, ?string $typeName): int|float|bool|string
	{
		return match ($typeName) {
			'int' => (int) $value,
			'float' => (float) $value,
			'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
			default => $value,
		};
	}
}
