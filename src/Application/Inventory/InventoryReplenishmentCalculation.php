<?php

declare(strict_types=1);

namespace App\Application\Inventory;

/** Exact quantities that explain one replenishment suggestion in inventory units. */
final readonly class InventoryReplenishmentCalculation
{
    public function __construct(
        public string $availableQuantity,
        public string $orderShortageQuantity,
        public string $minimumGapQuantity,
        public string $suggestedQuantity,
        public bool $reorderPointReached,
    ) {
    }
}
