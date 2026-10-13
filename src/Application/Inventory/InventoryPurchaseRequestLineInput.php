<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Materials\MaterialVariant;
use App\Entity\Suppliers\SupplierMaterialVariant;

/** One non-physical supply request line, expressed in the variant inventory unit. */
final readonly class InventoryPurchaseRequestLineInput
{
    public function __construct(
        public MaterialVariant $variant,
        public string $quantity,
        public ?SupplierMaterialVariant $supplierOffer = null,
    ) {
    }
}
