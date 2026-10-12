<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryLot;
use App\Entity\Materials\MaterialVariant;

/** Datos de una unidad física individual que llega a bodega. */
final readonly class InventoryReceiptLineInput
{
    /** @param array<string, mixed> $physicalAttributes */
    public function __construct(
        public MaterialVariant $variant,
        public string $quantity,
        public ?InventoryLot $lot = null,
        public ?string $storageLocation = null,
        public array $physicalAttributes = [],
        public ?string $unitCostMxn = null,
    ) {
    }
}
