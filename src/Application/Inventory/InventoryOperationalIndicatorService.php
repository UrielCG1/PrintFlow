<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Repository\Inventory\InventoryCycleCountRepository;
use App\Repository\Inventory\InventoryMovementRepository;
use App\Repository\Inventory\InventoryPurchaseRequestRepository;
use App\Repository\Inventory\InventoryShortageDecisionRepository;

/** Builds the dashboard and report data from immutable inventory events. */
final class InventoryOperationalIndicatorService
{
    public function __construct(
        private readonly InventoryCycleCountRepository $cycleCountRepository,
        private readonly InventoryMovementRepository $movementRepository,
        private readonly InventoryShortageDecisionRepository $shortageDecisionRepository,
        private readonly InventoryPurchaseRequestRepository $purchaseRequestRepository,
    ) {
    }

    public function collect(int $limit = 10, bool $includeDetails = true): InventoryOperationalIndicators
    {
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-30 days');

        return new InventoryOperationalIndicators(
            pendingCycleCounts: $this->cycleCountRepository->countPendingReview(),
            confirmedDifferencesLastThirtyDays: $this->cycleCountRepository->countApprovedDifferencesSince($since),
            wasteEventsLastThirtyDays: $this->movementRepository->countWasteEventsSince($since),
            ordersWaitingForPurchaseCount: $this->shortageDecisionRepository->countActiveWaiting(),
            purchasesInTrackingCount: $this->purchaseRequestRepository->countInProgress(),
            recentDifferences: $includeDetails ? $this->cycleCountRepository->findRecentDifferences($limit) : [],
            wasteByVariant: $includeDetails ? $this->movementRepository->findWasteSummarySince($since, $limit) : [],
            ordersWaitingForPurchase: $includeDetails ? $this->shortageDecisionRepository->findActiveWaitingForIndicators($limit) : [],
            trackedPurchases: $includeDetails ? $this->purchaseRequestRepository->findInProgress($limit) : [],
        );
    }
}
