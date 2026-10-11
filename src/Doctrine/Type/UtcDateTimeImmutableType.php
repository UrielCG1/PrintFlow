<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;

final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeInterface) {
            $value = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $format = $platform->getDateTimeFormatString();
        $wallTime = $value instanceof DateTimeInterface ? $value->format($format) : $value;
        if (!is_string($wallTime)) {
            throw new \UnexpectedValueException('El valor de fecha UTC debe ser una cadena o DateTimeInterface.');
        }

        $dateTime = DateTimeImmutable::createFromFormat('!'.$format, $wallTime, new DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new \UnexpectedValueException('El valor de fecha UTC no coincide con el formato de la plataforma.');
        }

        return $dateTime;
    }
}
