<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Equipment\Equipment;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionMaterialUsage;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Production\ProductionPrintedOutput;
use App\Entity\Production\ProductionPrintingScan;
use App\Entity\Production\ServiceOrderItemDesignRelease;
use App\Entity\Users\User;
use App\Enum\Inventory\QuantitySource;
use App\Enum\Orders\ServiceOrderItemDesignStatus;
use App\Enum\Production\CalculationMethod;
use App\Repository\Equipment\EquipmentRepository;
use App\Repository\Materials\MaterialVariantRepository;
use App\Repository\Production\ProductionPrintedOutputRepository;
use App\Repository\Production\ProductionPrintingScanRepository;
use App\Repository\Orders\ServiceOrderTraceabilityEventRepository;
use App\Repository\Production\ServiceOrderItemDesignReleaseRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderTraceabilityRecorder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Caso de uso de Impresión: requisitos, escaneo, consumo y salida identificada. */
final class PrintingManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EquipmentRepository $equipmentRepository,
        private readonly MaterialVariantRepository $materialVariantRepository,
        private readonly ServiceOrderItemDesignReleaseRepository $releaseRepository,
        private readonly ProductionPrintingScanRepository $scanRepository,
        private readonly ServiceOrderTraceabilityEventRepository $eventRepository,
        private readonly ProductionPrintedOutputRepository $outputRepository,
        private readonly ProductionExecutionManager $executionManager,
        private readonly ServiceOrderTraceabilityRecorder $traceabilityRecorder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function configureRequirements(
        ServiceOrderOperationPlan $plan,
        ?Equipment $equipment,
        MaterialVariant $material,
        string $reason,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($plan, $equipment, $material, $reason, $actor): void {
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            $this->lock($order, $plan);
            $this->assertPrintingPlan($plan);
            if ($plan->getBlockedReason() !== null) {
                throw new \DomainException('Resuelve el bloqueo operativo antes de cambiar los requisitos de Impresión.');
            }
            if ($plan->hasOpenExecution() || $plan->hasCompletedExecution()) {
                throw new \DomainException('No se pueden cambiar los requisitos después de iniciar Impresión. Registra un retrabajo autorizado.');
            }
            $reason = trim($reason);
            $machine = $equipment ?? $plan->getEquipment();
            if (!$machine instanceof Equipment) {
                throw new \DomainException('Selecciona la máquina prevista para Impresión.');
            }
            $this->assertEquipmentCompatible($plan, $machine);
            $this->assertMaterialUsable($material);
            if (($plan->getEquipment() !== $machine || $plan->getMaterialVariant() !== $material) && mb_strlen($reason) < 5) {
                throw new \DomainException('Explica el motivo de la asignación o sustitución técnica.');
            }
            $before = $this->requirementsState($plan);
            $plan->setEquipment($machine, $this->equipmentSnapshot($machine));
            $plan->setMaterialVariant($material, $this->materialSnapshot($material));
            $currentScan = $this->scanRepository->findCurrentForPlanForUpdate($plan);
            $currentScan?->invalidate();
            $order->touchTraceability();
            $after = $this->requirementsState($plan);
            $this->traceabilityRecorder->record(
                $order,
                'printing.requirements_configured',
                $actor,
                $before,
                $after,
                $plan->getServiceOrderItem(),
                $plan,
                null,
                $reason,
                ['changed_scan_invalidated' => $currentScan !== null],
            );
            $this->auditLogger->record($actor, 'printing.requirements_configured', 'service_order_operation_plan', $plan->getId(), $before, $after);
            $this->entityManager->flush();
        });
    }

    public function validateScans(
        ServiceOrderOperationPlan $plan,
        string $equipmentCode,
        string $materialBarcode,
        User $actor,
    ): PrintingScanResult {
        return $this->entityManager->wrapInTransaction(function () use ($plan, $equipmentCode, $materialBarcode, $actor): PrintingScanResult {
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            $this->lock($order, $plan);
            $this->assertPrintingPlan($plan);
            $this->assertAssignedOperator($plan, $actor);
            $this->assertReadyForPrint($order, $plan);

            $equipmentCode = strtoupper(trim($equipmentCode));
            $materialBarcode = strtoupper(trim($materialBarcode));
            $equipment = $this->equipmentRepository->findAvailableByCode($equipmentCode);
            $material = $this->materialVariantRepository->findActiveByBarcode($materialBarcode);
            $expectedEquipment = $plan->getEquipment();
            $expectedMaterial = $plan->getMaterialVariant();
            $release = $this->releaseRepository->findCurrentForUpdate($plan->getServiceOrderItem());
            $message = $this->scanMismatch($expectedEquipment, $expectedMaterial, $equipment, $material, $release);
            if ($message !== null) {
                $this->traceabilityRecorder->record(
                    $order,
                    'printing.scan_rejected',
                    $actor,
                    $this->requirementsState($plan),
                    $this->requirementsState($plan),
                    $plan->getServiceOrderItem(),
                    $plan,
                    null,
                    $message,
                    ['equipment_code' => $equipmentCode, 'material_barcode' => $materialBarcode],
                );
                $this->auditLogger->record($actor, 'printing.scan_rejected', 'service_order_operation_plan', $plan->getId(), null, ['reason' => $message]);
                $this->entityManager->flush();

                return new PrintingScanResult(false, $message);
            }

            $current = $this->scanRepository->findCurrentForPlanForUpdate($plan);
            $current?->invalidate();
            $scan = new ProductionPrintingScan(
                $plan,
                $release,
                $equipment,
                $material,
                $actor,
                $this->scanRequirementsSnapshot($plan, $release, $equipment, $material),
            );
            $this->entityManager->persist($scan);
            $order->touchTraceability();
            $this->traceabilityRecorder->record(
                $order,
                'printing.scans_validated',
                $actor,
                null,
                $this->requirementsState($plan),
                $plan->getServiceOrderItem(),
                $plan,
                null,
                null,
                ['scan_id' => $scan->getId(), 'design_release_version' => $release->getVersionNumber()],
            );
            $this->auditLogger->record($actor, 'printing.scans_validated', 'service_order_operation_plan', $plan->getId(), null, ['equipment_id' => $equipment->getId(), 'material_variant_id' => $material->getId()]);
            $this->entityManager->flush();

            return new PrintingScanResult(true, 'Máquina y material validados. Ya puedes iniciar la impresión.', $scan);
        });
    }

    public function start(ServiceOrderOperationPlan $plan, User $actor, ProductionExecutionCommand $command): ProductionOperationExecution
    {
        $scanId = null;
        $origin = $this->reworkOriginForPlan($plan);
        return $this->executionManager->start($plan, $actor, $command, $origin, function (ServiceOrder $order, ServiceOrderOperationPlan $lockedPlan, User $lockedActor) use (&$scanId): void {
            $this->assertPrintingPlan($lockedPlan);
            $scan = $this->scanRepository->findCurrentForPlanForUpdate($lockedPlan);
            $release = $this->releaseRepository->findCurrentForUpdate($lockedPlan->getServiceOrderItem());
            if (!$scan instanceof ProductionPrintingScan || !$release instanceof ServiceOrderItemDesignRelease || !$this->scanMatchesCurrentRequirements($scan, $lockedPlan, $release, $lockedActor)) {
                throw new \DomainException('Escanea nuevamente la máquina y el material; la validación anterior ya no corresponde a los requisitos vigentes.');
            }
            $scan->consume($lockedActor);
            $scanId = $scan->getId();
            $this->traceabilityRecorder->record(
                $order,
                'printing.scan_consumed',
                $lockedActor,
                null,
                null,
                $lockedPlan->getServiceOrderItem(),
                $lockedPlan,
                null,
                null,
                ['scan_id' => $scanId, 'design_release_version' => $release->getVersionNumber()],
            );
        });
    }

    public function complete(
        ProductionOperationExecution $execution,
        User $actor,
        ProductionExecutionCommand $command,
        string $total,
        ?string $waste,
        string $measurementMethod,
        ?string $wasteReason,
    ): ?ProductionPrintedOutput {
        $this->executionManager->complete($execution, $actor, $command, function (ServiceOrder $order, ServiceOrderOperationPlan $plan, ProductionOperationExecution $lockedExecution, User $lockedActor) use ($total, $waste, $measurementMethod, $wasteReason): void {
            $this->assertPrintingPlan($plan);
            $material = $plan->getMaterialVariant();
            $release = $this->releaseRepository->findCurrentForUpdate($plan->getServiceOrderItem());
            if (!$material instanceof MaterialVariant || !$release instanceof ServiceOrderItemDesignRelease) {
                throw new \DomainException('Faltan el material previsto o la liberación vigente de Diseño.');
            }
            if ($this->outputRepository->findOneBy(['execution' => $lockedExecution]) instanceof ProductionPrintedOutput) {
                throw new \DomainException('Este intento ya tiene una salida impresa registrada.');
            }
            $usage = new ProductionMaterialUsage(
                $plan->getServiceOrderItem(),
                $material,
                $material->getConsumptionUnit(),
                null,
                CalculationMethod::FIXED,
                1,
                ['version' => 'printing-capture-v1', 'planned_quantity' => null],
            );
            $usage->assignExecutionContext($plan, $lockedExecution, $this->materialSnapshot($material));
            $usage->recordActual($total, $waste, QuantitySource::MEASURED, $lockedActor, $measurementMethod, $wasteReason);
            $currentOutput = $this->outputRepository->findCurrentForUpdate($plan->getServiceOrderItem());
            if ($currentOutput instanceof ProductionPrintedOutput) {
                $origin = $lockedExecution->getOriginExecution();
                if (!$origin instanceof ProductionOperationExecution || !$this->eventRepository->hasQualityRejectionForExecution($origin)) {
                    throw new \DomainException('La partida ya tiene una salida impresa vigente. Calidad debe autorizar el retrabajo antes de sustituirla.');
                }
                $currentOutput->supersede();
                $this->traceabilityRecorder->record(
                    $order,
                    'printing.output_superseded',
                    $lockedActor,
                    null,
                    null,
                    $plan->getServiceOrderItem(),
                    $plan,
                    $lockedExecution,
                    'Sustitución de salida autorizada por retrabajo de Calidad.',
                    ['superseded_label_code' => $currentOutput->getLabelCode(), 'superseded_output_version' => $currentOutput->getVersionNumber()],
                );
            }
            $output = new ProductionPrintedOutput($plan->getServiceOrderItem(), $lockedExecution, $release, $this->outputRepository->nextVersion($plan->getServiceOrderItem()));
            $this->entityManager->persist($usage);
            $this->entityManager->persist($output);
            $this->traceabilityRecorder->record(
                $order,
                'printing.consumption_recorded',
                $lockedActor,
                null,
                null,
                $plan->getServiceOrderItem(),
                $plan,
                $lockedExecution,
                null,
                ['material_variant_id' => $material->getId(), 'total' => $usage->getActualQuantity(), 'waste' => $usage->getWasteQuantity(), 'unit' => $material->getConsumptionUnit()->getCode()],
            );
            $this->traceabilityRecorder->record(
                $order,
                'printing.output_created',
                $lockedActor,
                null,
                null,
                $plan->getServiceOrderItem(),
                $plan,
                $lockedExecution,
                null,
                ['label_code' => $output->getLabelCode(), 'output_version' => $output->getVersionNumber()],
            );
            $this->auditLogger->record($lockedActor, 'printing.output_created', 'production_printed_output', null, null, ['label_code' => $output->getLabelCode()]);
        });

        return $this->outputRepository->findOneBy(['execution' => $execution]);
    }

    public function recordLabelGenerated(ProductionPrintedOutput $output, User $actor, ?string $reason = null): void
    {
        $this->entityManager->wrapInTransaction(function () use ($output, $actor, $reason): void {
            $item = $output->getServiceOrderItem();
            $order = $item->getServiceOrder();
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($output, LockMode::PESSIMISTIC_WRITE);
            if (!$output->isCurrent()) {
                throw new \DomainException('La etiqueta corresponde a una salida sustituida.');
            }
            $wasReady = $output->getLabelReadyAt() !== null;
            $output->markLabelReady();
            $this->traceabilityRecorder->record(
                $order,
                $wasReady ? 'printing.label_reprinted' : 'printing.label_generated',
                $actor,
                null,
                null,
                $item,
                $output->getExecution()->getPlan(),
                $output->getExecution(),
                $reason,
                ['label_code' => $output->getLabelCode()],
            );
            $this->entityManager->flush();
        });
    }

    public function confirmLabelPlaced(ProductionPrintedOutput $output, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($output, $actor): void {
            $item = $output->getServiceOrderItem();
            $order = $item->getServiceOrder();
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($output, LockMode::PESSIMISTIC_WRITE);
            $output->confirmLabelPlaced($actor);
            $this->traceabilityRecorder->record($order, 'printing.label_placed', $actor, null, null, $item, $output->getExecution()->getPlan(), $output->getExecution(), null, ['label_code' => $output->getLabelCode()]);
            $this->entityManager->flush();
        });
    }

    public function isPrintingPlan(ServiceOrderOperationPlan $plan): bool
    {
        $areaCode = strtoupper($plan->getOperation()->getOperationArea()->getCode());
        $operationCode = strtoupper($plan->getOperation()->getCode());
        return in_array($areaCode, ['PRINT', 'PRINTING', 'IMPRESION'], true)
            || in_array($operationCode, ['PRINT', 'PRINTING', 'IMPRESION'], true);
    }

    public function reworkOriginForPlan(ServiceOrderOperationPlan $plan): ?ProductionOperationExecution
    {
        $order = $plan->getServiceOrderItem()->getServiceOrder();
        if ($order->getProductionStatus() !== \App\Enum\Orders\ProductionOrderStatus::QUALITY_REWORK || $plan->hasOpenExecution()) {
            return null;
        }
        $event = $this->eventRepository->findLatestQualityRejectionForPlan($plan);
        $origin = $event?->getOperationExecution();
        if (!$origin instanceof ProductionOperationExecution
            || $plan->getLatestExecution() !== $origin
            || $origin->getStatus() !== \App\Enum\Production\ProductionOperationExecutionStatus::COMPLETED
            || $plan->hasExecutionOrigin($origin)
        ) {
            return null;
        }

        return $origin;
    }

    private function assertPrintingPlan(ServiceOrderOperationPlan $plan): void
    {
        if (!$this->isPrintingPlan($plan)) {
            throw new \DomainException('La partida no pertenece a una operación de Impresión.');
        }
    }

    private function assertAssignedOperator(ServiceOrderOperationPlan $plan, User $actor): void
    {
        $assigned = $plan->getAssignedOperator();
        if ($assigned === null || !$this->sameUser($assigned, $actor)) {
            throw new \DomainException('Solo el operador asignado puede validar e iniciar esta impresión.');
        }
    }

    private function assertReadyForPrint(ServiceOrder $order, ServiceOrderOperationPlan $plan): void
    {
        if ($plan->getBlockedReason() !== null) {
            throw new \DomainException('Resuelve el bloqueo operativo antes de validar Impresión.');
        }
        if (!$order->isPlanned() || $plan->getServiceOrderItem()->getDesignStatus() !== ServiceOrderItemDesignStatus::READY_FOR_PRINT) {
            throw new \DomainException('Diseño debe liberar el arte y su captura antes de validar Impresión.');
        }
        $qualityRework = $this->reworkOriginForPlan($plan) !== null;
        if ($plan->hasOpenExecution() || ($plan->hasCompletedExecution() && !$qualityRework)) {
            throw new \DomainException('La operación de Impresión ya fue iniciada o terminada.');
        }
        foreach ($plan->getServiceOrderItem()->getActiveOperationPlans() as $previousPlan) {
            if ($previousPlan->getSequenceNumber() < $plan->getSequenceNumber()
                && (!$previousPlan->hasCompletedLatestExecution() || $previousPlan->hasOpenExecution())
            ) {
                throw new \DomainException('La operación anterior debe terminarse antes de validar Impresión.');
            }
        }
    }

    private function assertEquipmentCompatible(ServiceOrderOperationPlan $plan, Equipment $equipment): void
    {
        if (!$equipment->isSelectableForFutureExecution() || $equipment->getPrimaryOperation()->getId() !== $plan->getOperation()->getId()) {
            throw new \DomainException('La máquina no está disponible o no es compatible con esta operación de Impresión.');
        }
    }

    private function assertMaterialUsable(MaterialVariant $material): void
    {
        if (!$material->isActive() || !$material->isConsumable() || $material->getBarcode() === null || !$material->getConsumptionUnit()->isActive()) {
            throw new \DomainException('El material debe estar activo, ser consumible y tener código de barras y unidad vigentes.');
        }
    }

    private function scanMismatch(?Equipment $expectedEquipment, ?MaterialVariant $expectedMaterial, ?Equipment $scannedEquipment, ?MaterialVariant $scannedMaterial, ?ServiceOrderItemDesignRelease $release): ?string
    {
        if (!$expectedEquipment instanceof Equipment || !$expectedMaterial instanceof MaterialVariant) {
            return 'Faltan la máquina o el material previstos. Pide al jefe o Administrador configurar los requisitos técnicos.';
        }
        if (!$release instanceof ServiceOrderItemDesignRelease) {
            return 'No existe una liberación vigente de Diseño para esta partida.';
        }
        if (!$scannedEquipment instanceof Equipment) {
            return 'La máquina escaneada no existe, está inactiva o no está disponible.';
        }
        if (!$scannedMaterial instanceof MaterialVariant) {
            return 'El material escaneado no existe, está inactivo o no es consumible.';
        }
        if ($scannedEquipment->getId() !== $expectedEquipment->getId()) {
            return 'La máquina escaneada no coincide con la planificada. Solicita una sustitución técnica autorizada.';
        }
        if ($scannedMaterial->getId() !== $expectedMaterial->getId()) {
            return 'El material escaneado no coincide con el previsto. Solicita una sustitución técnica autorizada.';
        }
        return null;
    }

    private function scanMatchesCurrentRequirements(ProductionPrintingScan $scan, ServiceOrderOperationPlan $plan, ServiceOrderItemDesignRelease $release, User $actor): bool
    {
        return $scan->isAvailable()
            && $this->sameUser($scan->getOperator(), $actor)
            && $scan->getDesignRelease()->getId() === $release->getId()
            && $scan->getEquipment()->getId() === $plan->getEquipment()?->getId()
            && $scan->getMaterialVariant()->getId() === $plan->getMaterialVariant()?->getId();
    }

    private function lock(ServiceOrder $order, ServiceOrderOperationPlan $plan): void
    {
        $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
    }

    /** @return array<string, mixed> */
    private function equipmentSnapshot(Equipment $equipment): array
    {
        return ['id' => $equipment->getId(), 'code' => $equipment->getCode(), 'name' => $equipment->getName(), 'operation_id' => $equipment->getPrimaryOperation()->getId()];
    }

    /** @return array<string, mixed> */
    private function materialSnapshot(MaterialVariant $variant): array
    {
        return ['id' => $variant->getId(), 'code' => $variant->getCode(), 'barcode' => $variant->getBarcode(), 'material_code' => $variant->getMaterial()->getCode(), 'material_name' => $variant->getMaterial()->getName(), 'specifications' => $variant->getSpecifications(), 'consumption_unit' => ['id' => $variant->getConsumptionUnit()->getId(), 'code' => $variant->getConsumptionUnit()->getCode(), 'symbol' => $variant->getConsumptionUnit()->getSymbol()]];
    }

    /** @return array<string, mixed> */
    private function scanRequirementsSnapshot(ServiceOrderOperationPlan $plan, ServiceOrderItemDesignRelease $release, Equipment $equipment, MaterialVariant $material): array
    {
        return ['plan_id' => $plan->getId(), 'equipment' => $this->equipmentSnapshot($equipment), 'material' => $this->materialSnapshot($material), 'design_release_id' => $release->getId(), 'design_release_version' => $release->getVersionNumber()];
    }

    /** @return array<string, mixed> */
    private function requirementsState(ServiceOrderOperationPlan $plan): array
    {
        return ['equipment_id' => $plan->getEquipment()?->getId(), 'material_variant_id' => $plan->getMaterialVariant()?->getId(), 'material_snapshot' => $plan->getMaterialSnapshot()];
    }

    private function sameUser(User $left, User $right): bool
    {
        return $left === $right || ($left->getId() !== null && $left->getId() === $right->getId());
    }
}
