<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryShortageDecision;
use App\Entity\Users\User;
use App\Enum\Inventory\InventoryShortageDecisionType;
use App\Repository\Inventory\InventoryShortageDecisionRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderTraceabilityRecorder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Registra una sola decisión vigente por faltante y conserva las anteriores. */
final class InventoryShortageDecisionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryShortageDecisionRepository $decisionRepository,
        private readonly ServiceOrderTraceabilityRecorder $traceabilityRecorder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function decide(
        InventoryCommitment $commitment,
        InventoryShortageDecisionType $type,
        string $reason,
        User $actor,
    ): InventoryShortageDecision {
        return $this->entityManager->wrapInTransaction(function () use ($commitment, $type, $reason, $actor): InventoryShortageDecision {
            $order = $commitment->getServiceOrder();
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($commitment, LockMode::PESSIMISTIC_WRITE);
            if ($commitment->getShortageQuantity() === '0.000000') {
                throw new \DomainException('Este apartado ya no tiene faltante por resolver.');
            }

            $active = $this->decisionRepository->findActiveForCommitmentForUpdate($commitment);
            $active?->supersede();
            $decision = new InventoryShortageDecision($commitment, $type, $reason, $actor);
            $this->entityManager->persist($decision);

            $before = $order->traceabilityStateSnapshot();
            $order->touchTraceability();
            $after = $order->traceabilityStateSnapshot();
            $this->traceabilityRecorder->record(
                $order,
                'inventory.shortage_decision_recorded',
                $actor,
                $before,
                $after,
                null,
                null,
                null,
                $decision->getReason(),
                ['inventory_commitment_id' => $commitment->getId(), 'decision' => $type->value, 'shortage_quantity' => $commitment->getShortageQuantity()],
            );
            $this->auditLogger->record($actor, 'inventory.shortage_decision_recorded', 'inventory_shortage_decision', null, null, ['inventory_commitment_id' => $commitment->getId(), 'decision' => $type->value]);
            $this->entityManager->flush();

            return $decision;
        });
    }
}
