<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use Brick\Math\BigDecimal;

/** Calculates an explainable replenishment quantity without creating stock or reservations. */
final class InventoryReplenishmentCalculator
{
    public function calculate(
        string $availableQuantity,
        string $minimumStock,
        string $reorderPoint,
        string $reorderQuantity,
        string $orderShortageQuantity,
    ): InventoryReplenishmentCalculation {
        $available = $this->nonNegative($availableQuantity, 'El saldo disponible');
        $minimum = $this->nonNegative($minimumStock, 'El stock mínimo');
        $point = $this->nonNegative($reorderPoint, 'El punto de reabasto');
        $configuredQuantity = $this->nonNegative($reorderQuantity, 'La cantidad de reabasto');
        $shortage = $this->nonNegative($orderShortageQuantity, 'El faltante de órdenes');

        $minimumGap = $minimum->isGreaterThan($available)
            ? $minimum->minus($available)
            : BigDecimal::zero();
        $threshold = $point->isGreaterThan(BigDecimal::zero()) ? $point : $minimum;
        $reorderPointReached = $threshold->isGreaterThan(BigDecimal::zero()) && $available->isLessThanOrEqualTo($threshold);

        $suggested = $shortage->plus($minimumGap);
        if (($reorderPointReached || $shortage->isGreaterThan(BigDecimal::zero())) && $configuredQuantity->isGreaterThan($suggested)) {
            $suggested = $configuredQuantity;
        }

        return new InventoryReplenishmentCalculation(
            $available->toScale(6)->__toString(),
            $shortage->toScale(6)->__toString(),
            $minimumGap->toScale(6)->__toString(),
            $suggested->toScale(6)->__toString(),
            $reorderPointReached,
        );
    }

    private function nonNegative(string $quantity, string $field): BigDecimal
    {
        $quantity = trim(str_replace(',', '.', $quantity));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $quantity) !== 1) {
            throw new \InvalidArgumentException($field.' no tiene un formato válido.');
        }

        return BigDecimal::of($quantity);
    }
}
