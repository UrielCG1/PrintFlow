<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryMovement;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Inventory\InventoryStockUnitOperation;
use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Users\User;
use App\Enum\Inventory\InventoryStockUnitOperationReason;
use App\Enum\Inventory\InventoryStockUnitOperationType;
use App\Enum\Inventory\InventoryStockUnitStatus;
use App\Enum\Inventory\MovementType;
use App\Repository\Inventory\MaterialVariantCostRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Keeps QR-unit condition actions, ledger facts and projected balances in one transaction. */
final class InventoryStockUnitOperationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryStockUnitQrResolver $stockUnitQrResolver,
        private readonly MaterialVariantCostRepository $balanceRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function perform(InventoryStockUnitOperationCommand $command, User $actor): InventoryStockUnitOperation
    {
        return $this->entityManager->wrapInTransaction(function () use ($command, $actor): InventoryStockUnitOperation {
            $resolvedStockUnit = $this->stockUnitQrResolver->resolveForCount($command->qrPayload);
            $stockUnitId = $resolvedStockUnit->getId();
            if ($stockUnitId === null) {
                throw new \DomainException('La unidad física escaneada todavía no está registrada.');
            }

            $variant = $resolvedStockUnit->getVariant();
            $this->entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($resolvedStockUnit, LockMode::PESSIMISTIC_WRITE);
            $stockUnit = $resolvedStockUnit;
            $balance = $this->balanceRepository->findForUpdate($variant);
            if (!$balance instanceof MaterialVariantCost) {
                throw new \DomainException('No existe una proyección de saldo para la unidad escaneada. Realiza una conciliación administrativa antes de continuar.');
            }

            $previousStatus = $stockUnit->getStatus();
            $quantity = $stockUnit->getRemainingQuantity();
            $before = $this->balanceSnapshot($balance);

            $this->applyAction($stockUnit, $balance, $command->type, $quantity);

            $operation = new InventoryStockUnitOperation(
                $stockUnit,
                $actor,
                $command->type,
                $command->reason,
                $quantity,
                $previousStatus,
                $stockUnit->getStatus(),
                $command->note,
                $command->reference,
            );
            $this->entityManager->persist($operation);
            $this->entityManager->flush();

            if ($command->type === InventoryStockUnitOperationType::DISPOSE) {
                $movement = $this->withdrawalMovement($stockUnit, $actor, $command->reason, $quantity, $operation);
                $operation->attachMovement($movement);
                $this->entityManager->persist($movement);
            }

            $this->auditLogger->record(
                $actor,
                'inventory.stock_unit_operation.'.strtolower($command->type->value),
                'inventory_stock_unit_operation',
                $operation->getId(),
                $before + [
                    'stock_unit_status' => $previousStatus->value,
                    'stock_unit_remaining_quantity' => $quantity,
                ],
                $this->balanceSnapshot($balance) + [
                    'stock_unit_id' => $stockUnit->getId(),
                    'stock_unit_code' => $stockUnit->getUnitCode(),
                    'stock_unit_status' => $stockUnit->getStatus()->value,
                    'stock_unit_remaining_quantity' => $stockUnit->getRemainingQuantity(),
                    'operation_type' => $command->type->value,
                    'reason' => $command->reason->value,
                    'quantity' => $quantity,
                    'reference' => $operation->getReference(),
                    'movement_type' => $command->type === InventoryStockUnitOperationType::DISPOSE
                        ? $this->withdrawalMovementType($command->reason)->value
                        : null,
                ],
            );
            $this->entityManager->flush();

            return $operation;
        });
    }

    private function applyAction(
        InventoryStockUnit $stockUnit,
        MaterialVariantCost $balance,
        InventoryStockUnitOperationType $type,
        string $quantity,
    ): void {
        switch ($type) {
            case InventoryStockUnitOperationType::BLOCK:
                $this->block($stockUnit, $balance, $quantity);

                return;
            case InventoryStockUnitOperationType::RELEASE:
                $this->release($stockUnit, $balance, $quantity);

                return;
            case InventoryStockUnitOperationType::DISPOSE:
                $this->dispose($stockUnit, $balance, $quantity);

                return;
        }
    }

    private function block(InventoryStockUnit $stockUnit, MaterialVariantCost $balance, string $quantity): void
    {
        $stockUnit->block();
        $balance->block($quantity);
    }

    private function release(InventoryStockUnit $stockUnit, MaterialVariantCost $balance, string $quantity): void
    {
        $stockUnit->release();
        $balance->releaseBlocked($quantity);
    }

    private function dispose(InventoryStockUnit $stockUnit, MaterialVariantCost $balance, string $quantity): void
    {
        $wasBlocked = $stockUnit->getStatus() === InventoryStockUnitStatus::BLOCKED;
        $stockUnit->dispose();
        $balance->removeOnHand($quantity, $wasBlocked ? $quantity : '0');
    }

    private function withdrawalMovement(
        InventoryStockUnit $stockUnit,
        User $actor,
        InventoryStockUnitOperationReason $reason,
        string $quantity,
        InventoryStockUnitOperation $operation,
    ): InventoryMovement {
        $operationId = $operation->getId();
        if ($operationId === null) {
            throw new \LogicException('La operación de bodega debe tener un identificador antes de generar su movimiento.');
        }

        return (new InventoryMovement(
            $stockUnit->getVariant(),
            $stockUnit->getVariant()->getInventoryUnit(),
            $actor,
            $this->withdrawalMovementType($reason),
            '-'.$quantity,
            'inventory_stock_unit_operation',
        ))
            ->assignLot($stockUnit->getLot())
            ->assignStockUnit($stockUnit)
            ->setSourceId($operationId);
    }

    private function withdrawalMovementType(InventoryStockUnitOperationReason $reason): MovementType
    {
        return $reason === InventoryStockUnitOperationReason::RETURN_TO_SUPPLIER
            ? MovementType::RETURN
            : MovementType::WASTE;
    }

    /** @return array{on_hand_quantity: string, blocked_quantity: string, committed_quantity: string, available_quantity: string} */
    private function balanceSnapshot(MaterialVariantCost $balance): array
    {
        return [
            'on_hand_quantity' => $balance->getOnHandQuantity(),
            'blocked_quantity' => $balance->getBlockedQuantity(),
            'committed_quantity' => $balance->getCommittedQuantity(),
            'available_quantity' => $balance->getAvailableQuantity(),
        ];
    }
}
