<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory;

use App\Application\Inventory\InventoryCycleCountPriorityScorer;
use PHPUnit\Framework\TestCase;

final class InventoryCycleCountPriorityScorerTest extends TestCase
{
    public function testPrioritizesNeverCountedActiveUnitWithDifferencesAndValue(): void
    {
        $assessment = (new InventoryCycleCountPriorityScorer())->assess(
            null,
            12,
            2,
            '12000.000000',
            new \DateTimeImmutable('2026-10-12 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertSame(105, $assessment->score);
        self::assertSame('URGENTE', $assessment->level);
        self::assertSame([
            'Sin conteo registrado',
            '12 movimientos físicos recientes',
            '2 diferencias de conteo recientes',
            'Valor inventariado elevado',
        ], $assessment->reasons);
    }

    public function testUsesElapsedTimeWithoutPromotingRecentLowRiskUnit(): void
    {
        $scorer = new InventoryCycleCountPriorityScorer();
        $now = new \DateTimeImmutable('2026-10-12 12:00:00', new \DateTimeZone('UTC'));

        $overdue = $scorer->assess(new \DateTimeImmutable('2026-09-10 12:00:00', new \DateTimeZone('UTC')), 0, 0, '0', $now);
        $recent = $scorer->assess(new \DateTimeImmutable('2026-10-11 12:00:00', new \DateTimeZone('UTC')), 0, 0, '0', $now);

        self::assertSame(25, $overdue->score);
        self::assertSame('NORMAL', $overdue->level);
        self::assertSame(['Sin conteo desde hace 32 días'], $overdue->reasons);
        self::assertSame(0, $recent->score);
        self::assertSame([], $recent->reasons);
    }
}
