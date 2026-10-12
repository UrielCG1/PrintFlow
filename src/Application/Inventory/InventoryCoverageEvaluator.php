<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use Brick\Math\BigDecimal;

/** Calcula cobertura sin reservar ni descontar: la decisión se confirma después bajo bloqueo. */
final class InventoryCoverageEvaluator
{
    public function evaluate(string $requiredQuantity, string $availableQuantity): InventoryCoverage
    {
        $required = $this->positiveDecimal($requiredQuantity, 'La cantidad requerida');
        $available = $this->nonNegativeDecimal($availableQuantity, 'La cantidad disponible');
        $covered = $required->isLessThanOrEqualTo($available) ? $required : $available;

        return new InventoryCoverage(
            $required->toScale(6)->__toString(),
            $available->toScale(6)->__toString(),
            $covered->toScale(6)->__toString(),
            $required->minus($covered)->toScale(6)->__toString(),
        );
    }

    private function positiveDecimal(string $value, string $field): BigDecimal
    {
        $decimal = $this->nonNegativeDecimal($value, $field);
        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException($field.' debe ser mayor que cero.');
        }

        return $decimal;
    }

    private function nonNegativeDecimal(string $value, string $field): BigDecimal
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' no tiene un formato válido.');
        }

        return BigDecimal::of($value);
    }
}
