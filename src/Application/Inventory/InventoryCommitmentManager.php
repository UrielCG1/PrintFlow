<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryMovement;
use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
use App\Enum\Inventory\MovementType;
use App\Repository\Inventory\MaterialVariantCostRepository;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Confirma una cobertura consultada previamente bajo bloqueos de orden y variante. */
final class InventoryCommitmentManager
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly MaterialVariantCostRepository $balanceRepository, private readonly InventoryCommitmentRepository $commitmentRepository, private readonly InventoryCoverageEvaluator $coverageEvaluator, private readonly AuditLogger $auditLogger) {}

    public function commit(ServiceOrder $order, MaterialVariant $variant, string $requestedQuantity, User $actor): InventoryCommitment
    {
        return $this->entityManager->wrapInTransaction(function () use ($order, $variant, $requestedQuantity, $actor): InventoryCommitment {
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->commitmentRepository->findOneForOrderAndVariant($order, $variant);
            if ($existing instanceof InventoryCommitment) {
                if ($existing->getRequestedQuantity() === $this->coverageEvaluator->evaluate($requestedQuantity, '0')->requiredQuantity) {
                    return $existing;
                }
                throw new \DomainException('El apartado de '.$variant->getCode().' ya existe con una cantidad distinta. Libera o concilia el apartado antes de recalcular la previsión.');
            }
            $balance = $this->balanceRepository->findForUpdate($variant);
            if (!$balance instanceof MaterialVariantCost) { $balance = new MaterialVariantCost($variant); $this->entityManager->persist($balance); }
            $coverage = $this->coverageEvaluator->evaluate($requestedQuantity, $balance->getAvailableQuantity());
            $commitment = new InventoryCommitment($order, $variant, $actor, $coverage);
            $this->entityManager->persist($commitment);
            if ($coverage->coveredQuantity !== '0.000000') { $balance->commit($coverage->coveredQuantity); }
            $this->entityManager->flush();
            if ($coverage->coveredQuantity !== '0.000000') {
                $movement = new InventoryMovement($variant, $variant->getInventoryUnit(), $actor, MovementType::RESERVATION, '-'.$coverage->coveredQuantity, 'inventory_commitment');
                $movement->setSourceId($commitment->getId());
                $this->entityManager->persist($movement);
            }
            $this->auditLogger->record($actor, 'inventory.commitment.recorded', 'inventory_commitment', $commitment->getId(), null, ['service_order_id' => $order->getId(), 'variant_id' => $variant->getId(), 'committed_quantity' => $coverage->coveredQuantity, 'shortage_quantity' => $coverage->shortageQuantity]);
            $this->entityManager->flush();
            return $commitment;
        });
    }
}
