<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCommitmentConsumption;
use App\Entity\Inventory\InventoryMovement;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Production\ProductionMaterialUsage;
use App\Entity\Users\User;
use App\Enum\Inventory\MovementType;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Repository\Inventory\MaterialVariantCostRepository;
use App\Service\Audit\AuditLogger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Posts actual printing consumption against the scanned physical stock unit. */
final class InventoryPhysicalConsumptionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MaterialVariantCostRepository $balanceRepository,
        private readonly InventoryCommitmentRepository $commitmentRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function recordPrintingConsumption(
        ServiceOrder $order,
        ProductionMaterialUsage $usage,
        InventoryStockUnit $stockUnit,
        ?string $surplusQuantity,
        ?string $surplusLocation,
        ?string $surplusDescription,
        User $actor,
    ): InventoryPhysicalConsumptionResult {
        $variant = $usage->getVariant();
        $actual = $usage->getActualQuantity();
        $waste = $usage->getWasteQuantity();
        if ($usage->getId() === null || $variant === null || $actual === null || $waste === null || !$this->sameVariant($variant, $stockUnit->getVariant())) {
            throw new \DomainException('El consumo no tiene una unidad física y una variante de inventario consistentes.');
        }

        $this->entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->lock($stockUnit, LockMode::PESSIMISTIC_WRITE);
        if (!$stockUnit->isAvailable()) {
            throw new \DomainException('La unidad física escaneada ya no tiene saldo disponible. Escanea otra unidad y vuelve a iniciar la impresión.');
        }

        $consumedInventoryQuantity = $this->toInventoryQuantity($actual, $variant->getInventoryToConsumptionFactor());
        $wasteInventoryQuantity = $this->toInventoryQuantity($waste, $variant->getInventoryToConsumptionFactor());
        $productionInventoryQuantity = BigDecimal::of($consumedInventoryQuantity)->minus($wasteInventoryQuantity)->toScale(6)->__toString();

        $commitment = $this->commitmentRepository->findOneForOrderAndVariant($order, $variant);
        $releasedCommitmentQuantity = '0.000000';
        if ($commitment !== null) {
            $remainingCommitment = BigDecimal::of($commitment->getPendingQuantity());
            if ($remainingCommitment->isGreaterThan(BigDecimal::zero())) {
                $consumed = BigDecimal::of($consumedInventoryQuantity);
                $releasedCommitmentQuantity = ($consumed->isLessThan($remainingCommitment) ? $consumed : $remainingCommitment)
                    ->toScale(6)
                    ->__toString();
            }
        }

        $balance = $this->balanceRepository->findForUpdate($variant);
        if (!$balance instanceof MaterialVariantCost) {
            throw new \DomainException('No existe una proyección de saldo para el material escaneado. Registra el conteo inicial antes de consumirlo.');
        }

        $stockUnit->consume($consumedInventoryQuantity);
        $balance->consume($consumedInventoryQuantity, $releasedCommitmentQuantity);

        $primaryMovement = null;
        if (!BigDecimal::of($productionInventoryQuantity)->isZero()) {
            $primaryMovement = $this->movement($variant, $stockUnit, $actor, MovementType::PRODUCTION_CONSUMPTION, '-'.$productionInventoryQuantity, $usage->getId());
            $this->entityManager->persist($primaryMovement);
        }
        if (!BigDecimal::of($wasteInventoryQuantity)->isZero()) {
            $wasteMovement = $this->movement($variant, $stockUnit, $actor, MovementType::WASTE, '-'.$wasteInventoryQuantity, $usage->getId());
            $this->entityManager->persist($wasteMovement);
            $primaryMovement ??= $wasteMovement;
        }
        if (!$primaryMovement instanceof InventoryMovement) {
            throw new \LogicException('El consumo físico debe generar al menos un movimiento de inventario.');
        }
        $usage->attachInventoryMovement($primaryMovement);

        if ($commitment !== null && !BigDecimal::of($releasedCommitmentQuantity)->isZero()) {
            $commitment->recordConsumedQuantity($releasedCommitmentQuantity);
            $this->entityManager->persist(new InventoryCommitmentConsumption($commitment, $usage, $releasedCommitmentQuantity));
            $this->entityManager->persist((new InventoryMovement(
                $variant,
                $variant->getInventoryUnit(),
                $actor,
                MovementType::RELEASE,
                $releasedCommitmentQuantity,
                'production_material_usage',
            ))->setSourceId($usage->getId()));
        }

        $surplus = null;
        $surplusQuantity = $this->optionalPositiveQuantity($surplusQuantity, 'El sobrante separado');
        if ($surplusQuantity !== null) {
            $attributes = $stockUnit->getPhysicalAttributes() ?? [];
            $description = trim((string) $surplusDescription);
            if (mb_strlen($description) > 500) {
                throw new \InvalidArgumentException('La descripción del sobrante no puede superar 500 caracteres.');
            }
            if ($description !== '') {
                $attributes['surplus_description'] = $description;
            }
            $surplus = $stockUnit->separateSurplus($surplusQuantity, $surplusLocation, $attributes);
            $this->entityManager->persist($surplus);
            $this->entityManager->flush();

            $this->entityManager->persist($this->movement($variant, $stockUnit, $actor, MovementType::SURPLUS_TRANSFER_OUT, '-'.$surplusQuantity, $surplus->getId()));
            $this->entityManager->persist($this->movement($variant, $surplus, $actor, MovementType::SURPLUS_TRANSFER_IN, $surplusQuantity, $surplus->getId()));
        }

        $this->auditLogger->record($actor, 'inventory.physical_consumption.recorded', 'production_material_usage', $usage->getId(), null, [
            'stock_unit_id' => $stockUnit->getId(),
            'stock_unit_code' => $stockUnit->getUnitCode(),
            'consumed_inventory_quantity' => $consumedInventoryQuantity,
            'waste_inventory_quantity' => $wasteInventoryQuantity,
            'released_commitment_quantity' => $releasedCommitmentQuantity,
            'surplus_stock_unit_id' => $surplus?->getId(),
        ]);
        $this->entityManager->flush();

        return new InventoryPhysicalConsumptionResult($consumedInventoryQuantity, $wasteInventoryQuantity, $releasedCommitmentQuantity, $surplus);
    }

    private function toInventoryQuantity(string $consumptionQuantity, string $inventoryToConsumptionFactor): string
    {
        $factor = BigDecimal::of($inventoryToConsumptionFactor);
        if ($factor->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \DomainException('La variante escaneada no tiene una conversión válida entre inventario y consumo.');
        }

        return BigDecimal::of($consumptionQuantity)->dividedBy($factor, 6, RoundingMode::UP)->__toString();
    }

    private function optionalPositiveQuantity(?string $value, string $field): ?string
    {
        $value = trim(str_replace(',', '.', (string) $value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field.' debe ser un decimal positivo con máximo seis decimales.');
        }

        $quantity = BigDecimal::of($value)->toScale(6);
        if ($quantity->isZero()) {
            return null;
        }

        return $quantity->__toString();
    }

    private function movement(MaterialVariant $variant, InventoryStockUnit $stockUnit, User $actor, MovementType $type, string $quantity, ?int $sourceId): InventoryMovement
    {
        return (new InventoryMovement($variant, $variant->getInventoryUnit(), $actor, $type, $quantity, 'production_material_usage'))
            ->assignLot($stockUnit->getLot())
            ->assignStockUnit($stockUnit)
            ->setSourceId($sourceId);
    }

    private function sameVariant(MaterialVariant $left, MaterialVariant $right): bool
    {
        return $left === $right || ($left->getId() !== null && $left->getId() === $right->getId());
    }
}
