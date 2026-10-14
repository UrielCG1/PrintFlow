<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Materials\MaterialVariant;
use App\Entity\Suppliers\SupplierMaterialVariant;
use App\Entity\Inventory\InventoryPurchaseRequestLine;

/** One grouped material need shown on the replenishment tray. */
final readonly class InventoryReplenishmentSuggestion
{
    public function __construct(
        public MaterialVariant $variant,
        public InventoryReplenishmentCalculation $calculation,
        public int $shortageOrderCount,
        public int $waitingPurchaseOrderCount,
        public ?\DateTimeImmutable $requiredByOn,
        public ?SupplierMaterialVariant $supplierOffer,
        public ?InventoryPurchaseRequestLine $openPurchaseRequestLine,
    ) {
    }
}
