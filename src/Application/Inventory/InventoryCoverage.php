<?php

declare(strict_types=1);

namespace App\Application\Inventory;

/** Resultado inmutable de comparar una necesidad con el saldo utilizable. */
final readonly class InventoryCoverage
{
    public function __construct(
        public string $requiredQuantity,
        public string $availableQuantity,
        public string $coveredQuantity,
        public string $shortageQuantity,
    ) {
    }

    public function isFullyCovered(): bool
    {
        return $this->shortageQuantity === '0.000000';
    }

    public function hasPartialCoverage(): bool
    {
        return $this->coveredQuantity !== '0.000000' && !$this->isFullyCovered();
    }
}
