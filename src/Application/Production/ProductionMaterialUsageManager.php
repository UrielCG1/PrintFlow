<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Orders\ServiceOrderTraceabilityEvent;
use App\Entity\Production\ProductionMaterialUsage;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Users\User;
use App\Enum\Production\CalculationMethod;
use App\Enum\Inventory\QuantitySource;
use App\Repository\Orders\ServiceOrderTraceabilityEventRepository;
use App\Repository\Production\ProductionMaterialUsageRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderTraceabilityRecorder;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Capturas manuales de material previstas y usadas; nunca contabiliza existencias. */
final class ProductionMaterialUsageManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductionMaterialUsageRepository $usageRepository,
        private readonly ServiceOrderTraceabilityEventRepository $eventRepository,
        private readonly ServiceOrderTraceabilityRecorder $traceabilityRecorder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function addPlanned(
        ServiceOrderOperationPlan $plan,
        User $actor,
        ProductionExecutionCommand $command,
        string $materialName,
        string $quantity,
        string $unitLabel,
    ): void {
        $materialName = $this->validateMaterialName($materialName);
        $quantity = $this->positiveQuantity($quantity, 'La cantidad prevista');
        $unitLabel = $this->validateUnit($unitLabel);

        $this->entityManager->wrapInTransaction(function () use ($plan, $actor, $command, $materialName, $quantity, $unitLabel): void {
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
            $previous = $this->eventRepository->findOneByCommandId($command->commandId);
            if ($previous instanceof ServiceOrderTraceabilityEvent) {
                $this->assertReplay($previous, 'production.material_planned', $plan);
                return;
            }
            $this->assertCurrentVersion($order, $command);
            if (!$order->isPlanned() || !$plan->isActive() || $plan->hasOpenExecution() || $plan->hasCompletedExecution()) {
                throw new \DomainException('El material previsto solo se puede registrar antes de iniciar la operación.');
            }

            $usage = new ProductionMaterialUsage(
                $plan->getServiceOrderItem(),
                null,
                null,
                $quantity,
                CalculationMethod::FIXED,
                1,
                ['source' => 'manual-production-plan', 'version' => 1],
                $materialName,
                $unitLabel,
            );
            $usage->assignPlanContext($plan);
            $this->entityManager->persist($usage);

            $previousState = $order->traceabilityStateSnapshot();
            $order->touchTraceability();
            $newState = $order->traceabilityStateSnapshot();
            $this->traceabilityRecorder->record(
                $order,
                'production.material_planned',
                $actor,
                $previousState,
                $newState,
                $plan->getServiceOrderItem(),
                $plan,
                null,
                $command->note,
                ['material_name' => $materialName, 'planned_quantity' => $quantity, 'unit' => $unitLabel],
                $command->commandId,
                $command->commandId,
            );
            $this->auditLogger->record($actor, 'production.material_planned', 'service_order_operation_plan', $plan->getId(), $previousState, $newState);
            $this->entityManager->flush();
        });
    }

    public function recordActual(
        ProductionOperationExecution $execution,
        User $actor,
        ProductionExecutionCommand $command,
        string $materialName,
        string $quantity,
        string $unitLabel,
        string $waste = '0',
        ?string $wasteReason = null,
    ): void {
        $materialName = $this->validateMaterialName($materialName);
        $quantity = $this->positiveQuantity($quantity, 'La cantidad usada');
        $unitLabel = $this->validateUnit($unitLabel);

        $plan = $execution->getPlan();
        $order = $plan->getServiceOrderItem()->getServiceOrder();
        $this->entityManager->wrapInTransaction(function () use ($execution, $plan, $order, $actor, $command, $materialName, $quantity, $unitLabel, $waste, $wasteReason): void {
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($execution, LockMode::PESSIMISTIC_WRITE);
            $previous = $this->eventRepository->findOneByCommandId($command->commandId);
            if ($previous instanceof ServiceOrderTraceabilityEvent) {
                $this->assertReplay($previous, 'production.material_used', $plan, $execution);
                return;
            }
            $this->assertCurrentVersion($order, $command);
            if (!$plan->isActive()
                || $plan->getLatestExecution() !== $execution
                || !$execution->isOpen()
                || $execution->getStatus()->value !== 'IN_PROGRESS'
                || !$this->sameUser($execution->getAssignedOperator(), $actor)
            ) {
                throw new \DomainException('Solo la persona asignada puede registrar material en su operación en curso.');
            }

            $plannedQuantity = $this->findMatchingPlannedQuantity($plan, $materialName, $unitLabel);
            $snapshot = [
                'source' => 'manual-production-capture',
                'material_name' => $materialName,
                'unit' => $unitLabel,
            ];
            $usage = new ProductionMaterialUsage(
                $plan->getServiceOrderItem(),
                null,
                null,
                $plannedQuantity,
                CalculationMethod::FIXED,
                1,
                ['source' => 'manual-production-capture', 'version' => 1],
                $materialName,
                $unitLabel,
            );
            $usage->assignExecutionContext($plan, $execution, $snapshot);
            $usage->recordActual($quantity, $waste, QuantitySource::MEASURED, $actor, 'CAPTURA_MANUAL', $wasteReason);
            $this->entityManager->persist($usage);

            $previousState = $order->traceabilityStateSnapshot();
            $order->touchTraceability();
            $newState = $order->traceabilityStateSnapshot();
            $this->traceabilityRecorder->record(
                $order,
                'production.material_used',
                $actor,
                $previousState,
                $newState,
                $plan->getServiceOrderItem(),
                $plan,
                $execution,
                $command->note,
                [
                    'material_name' => $materialName,
                    'actual_quantity' => $usage->getActualQuantity(),
                    'waste_quantity' => $usage->getWasteQuantity(),
                    'unit' => $unitLabel,
                    'inventory_movement_created' => false,
                ],
                $command->commandId,
                $command->commandId,
            );
            $this->auditLogger->record($actor, 'production.material_used', 'production_operation_execution', $execution->getId(), $previousState, $newState);
            $this->entityManager->flush();
        });
    }

    private function findMatchingPlannedQuantity(ServiceOrderOperationPlan $plan, string $name, string $unit): ?string
    {
        foreach ($this->usageRepository->findPlannedForPlan($plan) as $planned) {
            if (mb_strtolower(trim($planned->getMaterialNameSnapshot())) === mb_strtolower($name)
                && mb_strtolower(trim($planned->getUnitLabelSnapshot())) === mb_strtolower($unit)
            ) {
                return $planned->getPlannedQuantity();
            }
        }

        return null;
    }

    private function assertCurrentVersion(\App\Entity\Orders\ServiceOrder $order, ProductionExecutionCommand $command): void
    {
        if ($order->getTraceabilityVersion() !== $command->expectedOrderVersion) {
            throw new \DomainException('La orden cambió desde que abriste esta pantalla. Actualízala antes de continuar.');
        }
    }

    private function assertReplay(ServiceOrderTraceabilityEvent $event, string $type, ServiceOrderOperationPlan $plan, ?ProductionOperationExecution $execution = null): void
    {
        if ($event->getEventType() !== $type || $event->getOperationPlan() !== $plan
            || ($execution !== null && $event->getOperationExecution() !== $execution)
        ) {
            throw new \DomainException('El identificador de comando ya se usó en otra acción.');
        }
    }

    private function validateMaterialName(string $name): string
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 180) {
            throw new \InvalidArgumentException('El nombre del material debe tener entre 2 y 180 caracteres.');
        }
        return $name;
    }

    private function validateUnit(string $unit): string
    {
        $unit = trim($unit);
        if ($unit === '' || mb_strlen($unit) > 40) {
            throw new \InvalidArgumentException('La unidad debe tener entre 1 y 40 caracteres.');
        }
        return $unit;
    }

    private function positiveQuantity(string $quantity, string $field): string
    {
        $quantity = trim(str_replace(',', '.', $quantity));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $quantity) !== 1
            || BigDecimal::of($quantity)->isLessThanOrEqualTo(BigDecimal::zero())
        ) {
            throw new \InvalidArgumentException($field.' debe ser una cantidad positiva con hasta seis decimales.');
        }
        return BigDecimal::of($quantity)->toScale(6)->__toString();
    }

    private function sameUser(User $left, User $right): bool
    {
        return $left === $right || ($left->getId() !== null && $left->getId() === $right->getId());
    }
}
