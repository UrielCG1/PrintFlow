<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Production;

use App\Application\Production\ProductionScheduleCalculator;
use App\Enum\Production\ProductionCalendarExceptionType;
use PHPUnit\Framework\TestCase;

final class ProductionScheduleCalculatorTest extends TestCase
{
    private const TZ = 'America/Mexico_City';

    public function testTwentyFourHoursAreSplitAcrossThreeTenHourWorkdays(): void
    {
        $segments = (new ProductionScheduleCalculator())->segments($this->local('2026-10-12 08:00'), 24 * 60);

        self::assertCount(3, $segments);
        self::assertSame([
            ['2026-10-12 08:00', '2026-10-12 18:00'],
            ['2026-10-13 08:00', '2026-10-13 18:00'],
            ['2026-10-14 08:00', '2026-10-14 12:00'],
        ], $this->format($segments));
    }

    public function testItSkipsWeekendsAndHolidaysAndAllowsAuthorizedOvertime(): void
    {
        $exceptions = [
            ['type' => ProductionCalendarExceptionType::HOLIDAY, 'start' => $this->local('2026-10-13 00:00'), 'end' => $this->local('2026-10-14 00:00')],
            ['type' => ProductionCalendarExceptionType::OVERTIME, 'start' => $this->local('2026-10-12 18:00'), 'end' => $this->local('2026-10-12 20:00')],
        ];
        $segments = (new ProductionScheduleCalculator())->segments($this->local('2026-10-12 17:00'), 180, [], $exceptions);

        self::assertSame([['2026-10-12 17:00', '2026-10-12 20:00']], $this->format($segments));
    }

    public function testItSkipsWeekendAndAFullDayHoliday(): void
    {
        $holiday = [[
            'type' => ProductionCalendarExceptionType::HOLIDAY,
            'start' => $this->local('2026-10-19 00:00'),
            'end' => $this->local('2026-10-20 00:00'),
        ]];
        $segments = (new ProductionScheduleCalculator())->segments($this->local('2026-10-16 17:00'), 180, [], $holiday);

        self::assertSame([
            ['2026-10-16 17:00', '2026-10-16 18:00'],
            ['2026-10-20 08:00', '2026-10-20 10:00'],
        ], $this->format($segments));
    }

    public function testReservationsAndMachineDowntimeAreNeverOverlapped(): void
    {
        $busy = [['start' => $this->local('2026-10-13 08:00'), 'end' => $this->local('2026-10-13 12:00')]];
        $exceptions = [[
            'type' => ProductionCalendarExceptionType::MAINTENANCE,
            'start' => $this->local('2026-10-13 14:00'),
            'end' => $this->local('2026-10-13 16:00'),
        ]];
        $segments = (new ProductionScheduleCalculator())->segments($this->local('2026-10-12 08:00'), 24 * 60, $busy, $exceptions);

        self::assertSame([
            ['2026-10-12 08:00', '2026-10-12 18:00'],
            ['2026-10-13 12:00', '2026-10-13 14:00'],
            ['2026-10-13 16:00', '2026-10-13 18:00'],
            ['2026-10-14 08:00', '2026-10-14 18:00'],
        ], $this->format($segments));
    }

    public function testHalfOpenIntervalsAllowBackToBackReservations(): void
    {
        $busy = [['start' => $this->local('2026-10-12 11:00'), 'end' => $this->local('2026-10-12 12:00')]];
        $segments = (new ProductionScheduleCalculator())->segments($this->local('2026-10-12 08:00'), 180, $busy);

        self::assertSame([
            ['2026-10-12 08:00', '2026-10-12 11:00'],
        ], $this->format($segments));
    }

    /** @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $segments
     * @return list<array{0: string, 1: string}> */
    private function format(array $segments): array
    {
        return array_map(static fn (array $segment): array => [
            $segment['start']->format('Y-m-d H:i'),
            $segment['end']->format('Y-m-d H:i'),
        ], $segments);
    }

    private function local(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone(self::TZ));
    }
}
