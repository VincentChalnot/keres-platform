<?php

declare(strict_types=1);

namespace App\Doctrine\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;

/**
 * `timestamptz(6)` - stock `DateTimeTzImmutableType` hard-codes `(0)` in its
 * DDL and its format string has no `u`, so microseconds never reach the
 * database (01-domain-model.md sec 2.3). Used only for `Game.clockTurnStartedAt`
 * and `Game.moveDeadlineAt` - the two anchors the clock needs sub-second
 * precision for.
 */
final class TimestampTzMicroType extends DateTimeTzImmutableType
{
    public const string NAME = 'timestamptz_micro';
    private const string FORMAT = 'Y-m-d H:i:s.uP';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TIMESTAMP(6) WITH TIME ZONE';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value->format(self::FORMAT);
        }

        throw InvalidType::new($value, static::class, ['null', \DateTimeImmutable::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $dateTime = \DateTimeImmutable::createFromFormat(self::FORMAT, $value);

        if (false !== $dateTime) {
            return $dateTime;
        }

        // Fall back to the parent's second-resolution format so rows written
        // before this type existed still hydrate.
        $dateTime = \DateTimeImmutable::createFromFormat($platform->getDateTimeTzFormatString(), $value);

        if (false !== $dateTime) {
            return $dateTime;
        }

        throw InvalidFormat::new($value, static::class, self::FORMAT);
    }
}
