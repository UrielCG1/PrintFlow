<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCycleCount;
use App\Entity\Inventory\InventoryMovement;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Users\User;
use App\Enum\Inventory\MovementType;
use App\Repository\Inventory\InventoryCycleCountRepository;
use App\Repository\Inventory\MaterialVariantCostRepository;
use App\Service\Audit\AuditLogger;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Captures QR counts and applies only administrator-reviewed differences. */
final class InventoryCycleCountManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryCycleCountRepository $cycleCountRepository,
        private readonly InventoryStockUnitQrResolver $stockUnitQrResolver,
        private readonly MaterialVariantCostRepository $balanceRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function capture(InventoryCycleCountCommand $command, User $actor): InventoryCycleCount
    {
        $fingerprint = $this->fingerprint($command);

        return $this->entityManager->wrapInTransaction(function () use ($command, $actor, $fingerprint): InventoryCycleCount {
            $existing = $this->cycleCountRepository->findOneByIdempotencyKey($command->idempotencyKey);
            if ($existing instanceof InventoryCycleCount) {
                $this->assertSameCommand($existing, $fingerprint);

                return $existing;
            }

            $stockUnit = $this->stockUnitQrResolver->resolveForCount($command->qrPayload);
            $variant = $stockUnit->getVariant();
            $this->entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($stockUnit, LockMode::PESSIMISTIC_WRITE);

            $pending = $this->cycleCountRepository->findPendingForStockUnit($stockUnit);
            if ($pending instanceof InventoryCycleCount) {
                throw new \DomainException('Esta unidad física ya tiene un conteo pendiente de revisión. Espera la confirmación o el reconteo solicitado.');
            }

            $existing = $this->cycleCountRepository->findOneByIdempotencyKey($command->idempotencyKey);
            if ($existing instanceof InventoryCycleCount) {
                $this->assertSameCommand($existing, $fingerprint);

                return $existing;
            }

            $cycleCount = new InventoryCycleCount(
                $stockUnit,
                $actor,
                $command->idempotencyKey,
                $fingerprint,
                $command->countedQuantity,
                $command->note,
            );
            $this->entityManager->persist($cycleCount);
            $this->entityManager->flush();

            $this->auditLogger->record($actor, 'inventory.cycle_count.captured', 'inventory_cycle_count', $cycleCount->getId(), null, [
                'stock_unit_id' => $stockUnit->getId(),
                'stock_unit_code' => $stockUnit->getUnitCode(),
                'variant_id' => $variant->getId(),
                'expected_quantity' => $cycleCount->getExpectedQuantity(),
                'stock_unit_version' => $cycleCount->getStockUnitVersion(),
                'counted_quantity' => $cycleCount->getCountedQuantity(),
                'difference_quantity' => $cycleCount->getDifferenceQuantity(),
            ]);
            $this->entityManager->flush();

            return $cycleCount;
        });
    }

    public function approve(InventoryCycleCount $submittedCount, ?string $reviewNote, User $actor): InventoryCycleCount
    {
        $id = $submittedCount->getId();
        if ($id === null) {
            throw new \InvalidArgumentException('El conteo indicado no está registrado.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($id, $reviewNote, $actor): InventoryCycleCount {
            $cycleCount = $this->cycleCountRepository->findForUpdate($id);
            if (!$cycleCount instanceof InventoryCycleCount) {
                throw new \DomainException('El conteo indicado ya no existe.');
            }
            if (!$cycleCount->isPendingReview()) {
                throw new \DomainException('El conteo ya fue revisado y no puede confirmarse otra vez.');
            }

            $variant = $cycleCount->getVariant();
            $stockUnit = $cycleCount->getStockUnit();
            $this->entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($stockUnit, LockMode::PESSIMISTIC_WRITE);

            if (
                !BigDecimal::of($stockUnit->getRemainingQuantity())->isEqualTo(BigDecimal::of($cycleCount->getExpectedQuantity()))
                || $stockUnit->getInventoryVersion() !== $cycleCount->getStockUnitVersion()
            ) {
                $cycleCount->markStale($actor);
                $this->auditLogger->record($actor, 'inventory.cycle_count.stale', 'inventory_cycle_count', $cycleCount->getId(), [
                    'expected_quantity' => $cycleCount->getExpectedQuantity(),
                    'stock_unit_version' => $cycleCount->getStockUnitVersion(),
                ], [
                    'current_quantity' => $stockUnit->getRemainingQuantity(),
                    'current_stock_unit_version' => $stockUnit->getInventoryVersion(),
                    'status' => $cycleCount->getStatus()->value,
                ]);
                $this->entityManager->flush();

                return $cycleCount;
            }

            $difference = $cycleCount->getDifferenceQuantity();
            $movement = null;
            if (!BigDecimal::of($difference)->isZero()) {
                $balance = $this->balanceRepository->findForUpdate($variant);
                if (!$balance instanceof MaterialVariantCost) {
                    $balance = new MaterialVariantCost($variant);
                    $this->entityManager->persist($balance);
                }

                $stockUnit->reconcileCountedQuantity($cycleCount->getCountedQuantity());
                $balance->applyCountAdjustment($difference);
                $movement = (new InventoryMovement(
                    $variant,
                    $variant->getInventoryUnit(),
                    $actor,
                    BigDecimal::of($difference)->isGreaterThan(BigDecimal::zero()) ? MovementType::ADJUSTMENT_IN : MovementType::ADJUSTMENT_OUT,
                    $difference,
                    'inventory_cycle_count',
                ))
                    ->assignLot($stockUnit->getLot())
                    ->assignStockUnit($stockUnit)
                    ->setSourceId($cycleCount->getId());
                $this->entityManager->persist($movement);
            }

            $cycleCount->approve($actor, $movement, $reviewNote);
            $this->auditLogger->record($actor, 'inventory.cycle_count.approved', 'inventory_cycle_count', $cycleCount->getId(), [
                'expected_quantity' => $cycleCount->getExpectedQuantity(),
            ], [
                'counted_quantity' => $cycleCount->getCountedQuantity(),
                'difference_quantity' => $difference,
                'movement_type' => $movement === null ? null : (BigDecimal::of($difference)->isGreaterThan(BigDecimal::zero()) ? MovementType::ADJUSTMENT_IN->value : MovementType::ADJUSTMENT_OUT->value),
                'stock_unit_id' => $stockUnit->getId(),
            ]);
            $this->entityManager->flush();

            return $cycleCount;
        });
    }

    public function reject(InventoryCycleCount $submittedCount, string $reviewNote, User $actor): InventoryCycleCount
    {
        $id = $submittedCount->getId();
        if ($id === null) {
            throw new \InvalidArgumentException('El conteo indicado no está registrado.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($id, $reviewNote, $actor): InventoryCycleCount {
            $cycleCount = $this->cycleCountRepository->findForUpdate($id);
            if (!$cycleCount instanceof InventoryCycleCount) {
                throw new \DomainException('El conteo indicado ya no existe.');
            }
            $cycleCount->reject($actor, $reviewNote);
            $this->auditLogger->record($actor, 'inventory.cycle_count.rejected', 'inventory_cycle_count', $cycleCount->getId(), null, [
                'review_note' => $cycleCount->getReviewNote(),
            ]);
            $this->entityManager->flush();

            return $cycleCount;
        });
    }

    private function assertSameCommand(InventoryCycleCount $cycleCount, string $fingerprint): void
    {
        if ($cycleCount->getCommandFingerprint() !== $fingerprint) {
            throw new \DomainException('La clave de idempotencia ya se utilizó para un conteo con datos distintos.');
        }
    }

    private function fingerprint(InventoryCycleCountCommand $command): string
    {
        $payload = InventoryStockUnitQrPayload::fromScanned($command->qrPayload)->toCanonicalPayload();
        $countedQuantity = trim(str_replace(',', '.', $command->countedQuantity));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $countedQuantity) !== 1) {
            throw new \InvalidArgumentException('La cantidad contada no tiene un formato válido.');
        }
        $note = trim((string) $command->note);
        if (mb_strlen($note) > 500) {
            throw new \InvalidArgumentException('La observación del conteo no puede superar 500 caracteres.');
        }

        return hash('sha256', json_encode([
            'counted_quantity' => BigDecimal::of($countedQuantity)->toScale(6)->__toString(),
            'note' => $note,
            'qr_payload' => $payload,
        ], JSON_THROW_ON_ERROR));
    }
}
