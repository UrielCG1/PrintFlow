<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryStockUnit;

final readonly class InventoryPhysicalConsumptionResult
{
    public function __construct(
        public string $consumedInventoryQuantity,
        public string $wasteInventoryQuantity,
        public string $releasedCommitmentQuantity,
        public ?InventoryStockUnit $surplusStockUnit,
    ) {
    }
}
