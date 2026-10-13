<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryMovement;
use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
use App\Enum\Inventory\MovementType;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Repository\Inventory\MaterialVariantCostRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderTraceabilityRecorder;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Libera el apartado no utilizado solo después de completar toda la orden. */
final class InventoryCommitmentReconciliationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryCommitmentRepository $commitmentRepository,
        private readonly MaterialVariantCostRepository $balanceRepository,
        private readonly ServiceOrderTraceabilityRecorder $traceabilityRecorder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function releaseUnusedForCompletedOrder(ServiceOrder $order, User $actor): InventoryCommitmentReconciliationResult
    {
        $releases = [];
        foreach ($this->commitmentRepository->findForOrderForUpdate($order) as $commitment) {
            $quantity = $commitment->releasePendingQuantity();
            if (BigDecimal::of($quantity)->isZero()) {
                continue;
            }

            $variant = $commitment->getVariant();
            $this->entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
            $balance = $this->balanceRepository->findForUpdate($variant);
            if (!$balance instanceof MaterialVariantCost) {
                throw new \LogicException('No existe saldo para liberar el apartado de '.$variant->getCode().'.');
            }
            $balance->releaseCommitment($quantity);

            $movement = new InventoryMovement(
                $variant,
                $variant->getInventoryUnit(),
                $actor,
                MovementType::RELEASE,
                $quantity,
                'inventory_commitment_reconciliation',
            );
            $movement->setSourceId($commitment->getId());
            $this->entityManager->persist($movement);
            $releases[] = [
                'commitment_id' => $commitment->getId(),
                'variant_code' => $variant->getCode(),
                'released_quantity' => $quantity,
            ];
        }

        if ($releases !== []) {
            $this->traceabilityRecorder->record(
                $order,
                'inventory.commitments_reconciled',
                $actor,
                null,
                null,
                null,
                null,
                null,
                'Se liberó el apartado no utilizado al terminar la producción.',
                ['releases' => $releases],
            );
            $this->auditLogger->record(
                $actor,
                'inventory.commitments.reconciled',
                'service_order',
                $order->getId(),
                null,
                ['releases' => $releases],
            );
        }

        return new InventoryCommitmentReconciliationResult($releases);
    }
}
