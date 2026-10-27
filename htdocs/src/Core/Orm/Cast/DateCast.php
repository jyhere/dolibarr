<?php

declare(strict_types=1);

namespace Dolibarr\Core\Orm\Cast;

/**
 * Dates (spec §5.6), as UserRepository::date(): read as written, labelled UTC ('' and 0000-00-00… are null);
 * bound with format() and no zone conversion, 'Y-m-d' for a day column, 'Y-m-d H:i:s' for a datetime.
 */
final class DateCast implements Cast
{
	public const DAY = 'Y-m-d';
	public const DATETIME = 'Y-m-d H:i:s';

	public function __construct(private readonly string $format)
	{
	}

	public function fromDatabase(mixed $value): ?\DateTimeImmutable
	{
		if ($value instanceof \DateTimeImmutable) {
			return $value;
		}
		if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
			return null;
		}
		try {
			return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
		} catch (\Exception) {
			return null;
		}
	}

	public function toDatabase(mixed $value): ?string
	{
		$date = $value instanceof \DateTimeInterface ? $value : $this->fromDatabase($value);

		return $date?->format($this->format);
	}
}
