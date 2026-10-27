<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Cast;

/**
 * Backed enum stored by value. Null or '' is null; an unknown value is refused (\ValueError), as from().
 *
 * @template TEnum of \BackedEnum
 */
final class EnumCast implements Cast
{
	private readonly bool $isIntBacked;

	/**
	 * @param class-string<TEnum> $enumClass
	 */
	public function __construct(private readonly string $enumClass)
	{
		$this->isIntBacked = (string) (new \ReflectionEnum($enumClass))->getBackingType() === 'int';
	}

	/**
	 * @return TEnum|null
	 * @throws \ValueError When the value is not a case of the enum
	 */
	public function fromDatabase(mixed $value): ?\BackedEnum
	{
		if ($value instanceof $this->enumClass) {
			return $value;
		}
		if ($value === null || $value === '') {
			return null;
		}
		$enumClass = $this->enumClass;
		if ($this->isIntBacked) {
			if (!is_numeric($value)) {
				throw new \ValueError(var_export($value, true).' is not a valid backing value for enum '.$enumClass);
			}

			return $enumClass::from((int) $value);
		}
		if (!is_scalar($value)) {
			throw new \ValueError('Invalid backing value for enum '.$enumClass);
		}

		return $enumClass::from((string) $value);
	}

	public function toDatabase(mixed $value): int|string|null
	{
		return $this->fromDatabase($value)?->value;
	}
}
