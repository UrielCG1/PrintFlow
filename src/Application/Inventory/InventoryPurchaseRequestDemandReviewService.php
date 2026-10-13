<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryPurchaseRequest;
use App\Entity\Inventory\InventoryPurchaseRequestLineDemand;
use App\Enum\Inventory\InventoryCommitmentStatus;
use App\Enum\Inventory\InventoryShortageDecisionType;
use App\Repository\Inventory\InventoryShortageDecisionRepository;
use Brick\Math\BigDecimal;

/** Flags purchase demand snapshots whose order decision or coverage has changed. */
final class InventoryPurchaseRequestDemandReviewService
{
    public function __construct(private readonly InventoryShortageDecisionRepository $decisionRepository)
    {
    }

    /** @return list<InventoryPurchaseRequestDemandReview> */
    public function review(InventoryPurchaseRequest $purchaseRequest): array
    {
        $requestId = $purchaseRequest->getId();

        return $requestId === null ? [] : ($this->reviewMany([$purchaseRequest])[$requestId] ?? []);
    }

    /** @param list<InventoryPurchaseRequest> $purchaseRequests @return array<int, list<InventoryPurchaseRequestDemandReview>> */
    public function reviewMany(array $purchaseRequests): array
    {
        $demandsByRequest = [];
        $commitments = [];
        foreach ($purchaseRequests as $purchaseRequest) {
            $requestId = $purchaseRequest->getId();
            if ($requestId === null) {
                continue;
            }
            foreach ($purchaseRequest->getLines() as $line) {
                foreach ($line->getDemands() as $demand) {
                    $demandsByRequest[$requestId][] = $demand;
                    $commitments[] = $demand->getCommitment();
                }
            }
        }
        $activeDecisions = $this->decisionRepository->findActiveForCommitments($commitments);

        $reviewsByRequest = [];
        foreach ($demandsByRequest as $requestId => $demands) {
            $reviewsByRequest[$requestId] = array_map(
                fn (InventoryPurchaseRequestLineDemand $demand): InventoryPurchaseRequestDemandReview => $this->reviewDemand($demand, $activeDecisions),
                $demands,
            );
        }

        return $reviewsByRequest;
    }

    /** @param array<int, \App\Entity\Inventory\InventoryShortageDecision> $activeDecisions */
    private function reviewDemand(InventoryPurchaseRequestLineDemand $demand, array $activeDecisions): InventoryPurchaseRequestDemandReview
    {
        $commitment = $demand->getCommitment();
        $commitmentId = $commitment->getId();
        $activeDecision = $commitmentId === null ? null : ($activeDecisions[$commitmentId] ?? null);

        if ($commitment->getStatus() === InventoryCommitmentStatus::SUBSTITUTED) {
            return new InventoryPurchaseRequestDemandReview($demand, 'Sustituida por material autorizado', true, $activeDecision);
        }
        if (BigDecimal::of($commitment->getShortageQuantity())->isZero()) {
            return new InventoryPurchaseRequestDemandReview($demand, 'Cobertura recuperada', true, $activeDecision);
        }
        if ($activeDecision?->getType() === InventoryShortageDecisionType::WAIT_FOR_PURCHASE) {
            return new InventoryPurchaseRequestDemandReview($demand, 'En espera de compra', false, $activeDecision);
        }
        if ($activeDecision !== null) {
            return new InventoryPurchaseRequestDemandReview($demand, 'Decisión cambiada: '.$activeDecision->getType()->label(), true, $activeDecision);
        }

        return new InventoryPurchaseRequestDemandReview($demand, 'Espera retirada o modificada', true, null);
    }
}
