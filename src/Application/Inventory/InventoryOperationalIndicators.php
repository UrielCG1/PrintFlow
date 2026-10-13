<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCycleCount;
use App\Entity\Inventory\InventoryPurchaseRequest;
use App\Entity\Inventory\InventoryShortageDecision;

/** Read-only operating indicators for inventory follow-up. */
final readonly class InventoryOperationalIndicators
{
    /**
     * @param list<InventoryCycleCount> $recentDifferences
     * @param list<array{variant_id: string, material_name: string, variant_code: string, unit_symbol: string, waste_quantity: string}> $wasteByVariant
     * @param list<InventoryShortageDecision> $ordersWaitingForPurchase
     * @param list<InventoryPurchaseRequest> $trackedPurchases
     */
    public function __construct(
        public int $pendingCycleCounts,
        public int $confirmedDifferencesLastThirtyDays,
        public int $wasteEventsLastThirtyDays,
        public int $ordersWaitingForPurchaseCount,
        public int $purchasesInTrackingCount,
        public array $recentDifferences,
        public array $wasteByVariant,
        public array $ordersWaitingForPurchase,
        public array $trackedPurchases,
    ) {
    }
}
