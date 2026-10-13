<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryPurchaseRequestLineDemand;
use App\Entity\Inventory\InventoryShortageDecision;

/** Current operational state of a waiting-order demand linked to a purchase line. */
final readonly class InventoryPurchaseRequestDemandReview
{
    public function __construct(
        public InventoryPurchaseRequestLineDemand $demand,
        public string $label,
        public bool $requiresReview,
        public ?InventoryShortageDecision $activeDecision,
    ) {
    }
}
