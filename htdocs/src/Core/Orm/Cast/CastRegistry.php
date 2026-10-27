<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Cast;

use Dolibarr\Core\Orm\ColumnMapping;

/**
 * The cast of a mapped column, one shared instance per kind.
 */
final class CastRegistry
{
	/** @var array<string, Cast> */
	private array $casts = [];

	/**
	 * @throws \LogicException When the column has an unknown cast
	 */
	public function for(ColumnMapping $column): Cast
	{
		$key = $column->cast.'|'.($column->nullable ? '1' : '0').'|'.($column->enumClass ?? '');

		return $this->casts[$key] ??= match ($column->cast) {
			'int' => new IntCast($column->nullable),
			'float' => new FloatCast($column->nullable),
			'bool' => new BoolCast($column->nullable),
			'string' => new StringCast($column->nullable),
			'json' => new JsonCast($column->nullable),
			'date' => new DateCast(DateCast::DAY),
			'datetime' => new DateCast(DateCast::DATETIME),
			'enum' => $column->enumClass !== null ? new EnumCast($column->enumClass) : throw new \LogicException('Column '.$column->name.': enum cast without enum class'),
			default => throw new \LogicException('Column '.$column->name.': unknown cast '.$column->cast),
		};
	}
}
