<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use Brick\Math\BigDecimal;

/**
 * Scores QR units from observable inventory risk without exposing their cost
 * to the printing area. The route favors units never counted, with activity,
 * prior discrepancies, elapsed time, or significant value.
 */
final class InventoryCycleCountPriorityScorer
{
    public function assess(
        ?\DateTimeInterface $lastCountedAt,
        int $recentActivityCount,
        int $recentDifferenceCount,
        string $estimatedValueMxn,
        \DateTimeImmutable $now,
    ): InventoryCycleCountPriorityAssessment {
        $score = 0;
        $reasons = [];
        $recentActivityCount = max(0, $recentActivityCount);
        $recentDifferenceCount = max(0, $recentDifferenceCount);

        if ($lastCountedAt === null) {
            $score += 45;
            $reasons[] = 'Sin conteo registrado';
        } else {
            $elapsedDays = max(0, intdiv(max(0, $now->getTimestamp() - $lastCountedAt->getTimestamp()), 86400));
            if ($elapsedDays >= 30) {
                $score += 25;
                $reasons[] = sprintf('Sin conteo desde hace %d días', $elapsedDays);
            } elseif ($elapsedDays >= 14) {
                $score += 15;
                $reasons[] = sprintf('Sin conteo desde hace %d días', $elapsedDays);
            } elseif ($elapsedDays >= 7) {
                $score += 8;
                $reasons[] = sprintf('Sin conteo desde hace %d días', $elapsedDays);
            }
        }

        if ($recentActivityCount >= 10) {
            $score += 20;
            $reasons[] = sprintf('%d movimientos físicos recientes', $recentActivityCount);
        } elseif ($recentActivityCount >= 4) {
            $score += 12;
            $reasons[] = sprintf('%d movimientos físicos recientes', $recentActivityCount);
        } elseif ($recentActivityCount > 0) {
            $score += 6;
            $reasons[] = sprintf('%d movimiento físico reciente', $recentActivityCount);
        }

        if ($recentDifferenceCount >= 2) {
            $score += 25;
            $reasons[] = sprintf('%d diferencias de conteo recientes', $recentDifferenceCount);
        } elseif ($recentDifferenceCount === 1) {
            $score += 15;
            $reasons[] = 'Una diferencia de conteo reciente';
        }

        $estimatedValue = $this->nonNegativeDecimal($estimatedValueMxn);
        if ($estimatedValue->isGreaterThanOrEqualTo(BigDecimal::of('10000'))) {
            $score += 15;
            $reasons[] = 'Valor inventariado elevado';
        } elseif ($estimatedValue->isGreaterThanOrEqualTo(BigDecimal::of('1000'))) {
            $score += 8;
            $reasons[] = 'Valor inventariado relevante';
        } elseif (!$estimatedValue->isZero()) {
            $score += 3;
        }

        return new InventoryCycleCountPriorityAssessment(
            $score,
            $score >= 60 ? 'URGENTE' : ($score >= 35 ? 'ALTA' : 'NORMAL'),
            $reasons,
        );
    }

    private function nonNegativeDecimal(string $value): BigDecimal
    {
        try {
            $decimal = BigDecimal::of(trim($value));
        } catch (\Throwable) {
            return BigDecimal::zero();
        }

        return $decimal->isNegative() ? BigDecimal::zero() : $decimal;
    }
}
