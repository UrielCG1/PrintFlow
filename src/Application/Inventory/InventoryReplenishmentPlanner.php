<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\MaterialVariantCost;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Repository\Inventory\InventoryShortageDecisionRepository;
use App\Repository\Inventory\MaterialVariantCostRepository;
use App\Repository\Inventory\InventoryPurchaseRequestLineRepository;
use App\Repository\Materials\MaterialVariantRepository;
use App\Repository\Suppliers\SupplierMaterialVariantRepository;
use Brick\Math\BigDecimal;

/** Groups actual shortages and configured minimums into a non-posting purchase suggestion. */
final class InventoryReplenishmentPlanner
{
    public function __construct(
        private readonly MaterialVariantRepository $variantRepository,
        private readonly MaterialVariantCostRepository $balanceRepository,
        private readonly InventoryCommitmentRepository $commitmentRepository,
        private readonly InventoryShortageDecisionRepository $decisionRepository,
        private readonly InventoryPurchaseRequestLineRepository $purchaseRequestLineRepository,
        private readonly SupplierMaterialVariantRepository $supplierMaterialVariantRepository,
        private readonly InventoryReplenishmentCalculator $calculator,
    ) {
    }

    /** @return list<InventoryReplenishmentSuggestion> */
    public function plan(): array
    {
        $variants = $this->variantRepository->findActiveStockItems();
        if ($variants === []) {
            return [];
        }

        $balancesByVariant = [];
        foreach ($this->balanceRepository->findForVariants($variants) as $balance) {
            $balancesByVariant[(int) $balance->getVariant()->getId()] = $balance;
        }

        $waitingCommitmentIds = array_fill_keys($this->decisionRepository->findActiveWaitingCommitmentIds(), true);
        $trackedPurchaseQuantities = $this->purchaseRequestLineRepository->findTrackedPendingQuantitiesByVariant();
        $openPurchaseLines = [];
        foreach ($this->purchaseRequestLineRepository->findOpenForVariants($variants) as $line) {
            $openPurchaseLines[(int) $line->getVariant()->getId()] = $line;
        }
        $shortagesByVariant = [];
        foreach ($this->commitmentRepository->findShortagesForReplenishment() as $commitment) {
            $variantId = (int) $commitment->getVariant()->getId();
            if (!isset($shortagesByVariant[$variantId])) {
                $shortagesByVariant[$variantId] = [
                    'quantity' => BigDecimal::zero(),
                    'order_ids' => [],
                    'waiting_order_ids' => [],
                ];
            }
            $shortagesByVariant[$variantId]['quantity'] = $shortagesByVariant[$variantId]['quantity']->plus($commitment->getShortageQuantity());
            $orderId = (int) $commitment->getServiceOrder()->getId();
            $shortagesByVariant[$variantId]['order_ids'][$orderId] = true;
            if (isset($waitingCommitmentIds[(int) $commitment->getId()])) {
                $shortagesByVariant[$variantId]['waiting_order_ids'][$orderId] = true;
            }
        }

        $offerByVariant = [];
        foreach ($this->supplierMaterialVariantRepository->findActiveForVariants($variants) as $offer) {
            $variantId = (int) $offer->getVariant()->getId();
            $offerByVariant[$variantId] ??= $offer;
        }

        $suggestions = [];
        foreach ($variants as $variant) {
            $variantId = (int) $variant->getId();
            /** @var MaterialVariantCost|null $balance */
            $balance = $balancesByVariant[$variantId] ?? null;
            $shortage = $shortagesByVariant[$variantId] ?? null;
            $calculation = $this->calculator->calculate(
                $balance?->getAvailableQuantity() ?? '0.000000',
                $variant->getMinimumStock(),
                $variant->getReorderPoint(),
                $variant->getReorderQuantity(),
                $shortage === null ? '0.000000' : $shortage['quantity']->toScale(6)->__toString(),
                $trackedPurchaseQuantities[$variantId] ?? '0.000000',
            );
            if (BigDecimal::of($calculation->suggestedQuantity)->isZero()) {
                continue;
            }

            $suggestions[] = new InventoryReplenishmentSuggestion(
                $variant,
                $calculation,
                $shortage === null ? 0 : count($shortage['order_ids']),
                $shortage === null ? 0 : count($shortage['waiting_order_ids']),
                $offerByVariant[$variantId] ?? null,
                $openPurchaseLines[$variantId] ?? null,
            );
        }

        usort($suggestions, static function (InventoryReplenishmentSuggestion $left, InventoryReplenishmentSuggestion $right): int {
            $waitingComparison = $right->waitingPurchaseOrderCount <=> $left->waitingPurchaseOrderCount;
            if ($waitingComparison !== 0) {
                return $waitingComparison;
            }
            $shortageComparison = BigDecimal::of($right->calculation->orderShortageQuantity)->compareTo($left->calculation->orderShortageQuantity);
            if ($shortageComparison !== 0) {
                return $shortageComparison;
            }

            return strcmp($left->variant->getCode(), $right->variant->getCode());
        });

        return $suggestions;
    }
}
