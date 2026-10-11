<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Enum\Production\ProductionCalendarExceptionType;

final class ProductionScheduleCalculator
{
    private const TIMEZONE = 'America/Mexico_City';
    private const WORK_START_HOUR = 8;
    private const WORK_END_HOUR = 18;

    /**
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $occupied
     * @param list<array{type: ProductionCalendarExceptionType, start: \DateTimeImmutable, end: \DateTimeImmutable}> $exceptions
     * @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    public function segments(
        \DateTimeImmutable $startAfter,
        int $durationMinutes,
        array $occupied = [],
        array $exceptions = [],
        int $horizonDays = 730,
    ): array {
        if ($durationMinutes < 1 || $durationMinutes > 525600) {
            throw new \InvalidArgumentException('La duración debe estar entre 1 minuto y un año.');
        }

        $timezone = new \DateTimeZone(self::TIMEZONE);
        $cursorDay = $startAfter->setTimezone($timezone)->setTime(0, 0);
        $remainingSeconds = $durationMinutes * 60;
        $segments = [];

        for ($dayNumber = 0; $dayNumber < $horizonDays && $remainingSeconds > 0; ++$dayNumber) {
            $dayStart = $cursorDay->modify(sprintf('+%d days', $dayNumber));
            $dayEnd = $dayStart->modify('+1 day');
            if ($this->isHoliday($dayStart, $dayEnd, $exceptions)) {
                continue;
            }

            $windows = $this->workingWindows($dayStart, $dayEnd, $exceptions);
            $blocked = $this->blockedWindows($dayStart, $dayEnd, $occupied, $exceptions);

            foreach ($windows as [$windowStart, $windowEnd]) {
                $windowStart = max($windowStart, $startAfter->getTimestamp());
                if ($windowEnd <= $windowStart) {
                    continue;
                }

                $freeWindows = $this->subtract($windowStart, $windowEnd, $blocked);
                foreach ($freeWindows as [$freeStart, $freeEnd]) {
                    if ($remainingSeconds <= 0) {
                        break 2;
                    }
                    $seconds = min($remainingSeconds, $freeEnd - $freeStart);
                    if ($seconds <= 0) {
                        continue;
                    }
                    $segments[] = [
                        'start' => (new \DateTimeImmutable('@'.$freeStart))->setTimezone($timezone),
                        'end' => (new \DateTimeImmutable('@'.($freeStart + $seconds)))->setTimezone($timezone),
                    ];
                    $remainingSeconds -= $seconds;
                }
            }
        }

        if ($remainingSeconds > 0) {
            throw new \DomainException('No hay espacio laborable suficiente dentro de los próximos 730 días.');
        }

        return $segments;
    }

    /** @param list<array{type: ProductionCalendarExceptionType, start: \DateTimeImmutable, end: \DateTimeImmutable}> $exceptions */
    private function isHoliday(\DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd, array $exceptions): bool
    {
        foreach ($exceptions as $exception) {
            if ($exception['type'] === ProductionCalendarExceptionType::HOLIDAY
                && $this->overlaps($dayStart->getTimestamp(), $dayEnd->getTimestamp(), $exception['start']->getTimestamp(), $exception['end']->getTimestamp())) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{type: ProductionCalendarExceptionType, start: \DateTimeImmutable, end: \DateTimeImmutable}> $exceptions
     *  @return list<array{0: int, 1: int}> */
    private function workingWindows(\DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd, array $exceptions): array
    {
        $windows = [];
        if ((int) $dayStart->format('N') <= 5) {
            $windows[] = [$dayStart->setTime(self::WORK_START_HOUR, 0)->getTimestamp(), $dayStart->setTime(self::WORK_END_HOUR, 0)->getTimestamp()];
        }

        foreach ($exceptions as $exception) {
            if ($exception['type'] !== ProductionCalendarExceptionType::OVERTIME) {
                continue;
            }
            $start = max($dayStart->getTimestamp(), $exception['start']->getTimestamp());
            $end = min($dayEnd->getTimestamp(), $exception['end']->getTimestamp());
            if ($end > $start) {
                $windows[] = [$start, $end];
            }
        }

        usort($windows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($windows as [$start, $end]) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0 && $start <= $merged[$lastIndex][1]) {
                $merged[$lastIndex][1] = max($merged[$lastIndex][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /** @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $occupied
     * @param list<array{type: ProductionCalendarExceptionType, start: \DateTimeImmutable, end: \DateTimeImmutable}> $exceptions
     * @return list<array{0: int, 1: int}> */
    private function blockedWindows(\DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd, array $occupied, array $exceptions): array
    {
        $blocks = [];
        foreach ($occupied as $interval) {
            $blocks[] = [$interval['start']->getTimestamp(), $interval['end']->getTimestamp()];
        }
        foreach ($exceptions as $exception) {
            if (!in_array($exception['type'], [ProductionCalendarExceptionType::MAINTENANCE, ProductionCalendarExceptionType::OUTAGE], true)) {
                continue;
            }
            if ($this->overlaps($dayStart->getTimestamp(), $dayEnd->getTimestamp(), $exception['start']->getTimestamp(), $exception['end']->getTimestamp())) {
                $blocks[] = [$exception['start']->getTimestamp(), $exception['end']->getTimestamp()];
            }
        }
        usort($blocks, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $blocks;
    }

    /** @param list<array{0: int, 1: int}> $blocks
     * @return list<array{0: int, 1: int}> */
    private function subtract(int $start, int $end, array $blocks): array
    {
        $free = [];
        $cursor = $start;
        foreach ($blocks as [$blockStart, $blockEnd]) {
            if ($blockEnd <= $cursor || $blockStart >= $end) {
                continue;
            }
            if ($blockStart > $cursor) {
                $free[] = [$cursor, min($blockStart, $end)];
            }
            $cursor = max($cursor, $blockEnd);
            if ($cursor >= $end) {
                break;
            }
        }
        if ($cursor < $end) {
            $free[] = [$cursor, $end];
        }

        return $free;
    }

    private function overlaps(int $leftStart, int $leftEnd, int $rightStart, int $rightEnd): bool
    {
        return $leftStart < $rightEnd && $leftEnd > $rightStart;
    }
}
