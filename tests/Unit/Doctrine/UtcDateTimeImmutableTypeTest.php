<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\Type\UtcDateTimeImmutableType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;

final class UtcDateTimeImmutableTypeTest extends TestCase
{
    public function testItStoresLocalInputAsUtcAndHydratesUtcWallTimeAsUtc(): void
    {
        $type = new UtcDateTimeImmutableType();
        $platform = new MySQLPlatform();
        $local = new DateTimeImmutable('2026-10-12 08:00:00', new DateTimeZone('America/Mexico_City'));

        self::assertSame('2026-10-12 14:00:00', $type->convertToDatabaseValue($local, $platform));

        $hydrated = $type->convertToPHPValue('2026-10-12 14:00:00', $platform);
        self::assertSame('UTC', $hydrated?->getTimezone()->getName());
        self::assertSame('2026-10-12 14:00:00', $hydrated?->format('Y-m-d H:i:s'));
    }
}
