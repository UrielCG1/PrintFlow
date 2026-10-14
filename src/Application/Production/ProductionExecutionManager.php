<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Orders\ServiceOrderTraceabilityEvent;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Users\User;
use App\Application\Inventory\InventoryProductionAuthorizationGuard;
use App\Application\Inventory\InventoryCommitmentReconciliationManager;
use App\Enum\Orders\ProductionOrderStatus;
use App\Enum\Orders\ServiceOrderItemDesignStatus;
use App\Repository\Orders\ServiceOrderTraceabilityEventRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderTraceabilityRecorder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Coordina los intentos de producción en una transacción.
 *
 * El bloqueo siempre sigue el mismo orden: orden, plan y después intento. El
 * evento con commandId da una respuesta idempotente a reintentos de la tablet.
 */
final class ProductionExecutionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceOrderTraceabilityEventRepository $eventRepository,
        private readonly ServiceOrderTraceabilityRecorder $traceabilityRecorder,
        private readonly AuditLogger $auditLogger,
        private readonly ProductionScheduleLock $scheduleLock,
        private readonly ProductionPriorityRescheduler $rescheduler,
        private readonly \App\Repository\Orders\ServiceOrderOperationPlanRepository $planRepository,
        private readonly InventoryProductionAuthorizationGuard $inventoryAuthorizationGuard,
        private readonly InventoryCommitmentReconciliationManager $inventoryCommitmentReconciliationManager,
    ) {
    }

    public function start(
        ServiceOrderOperationPlan $plan,
        User $actor,
        ProductionExecutionCommand $command,
        ?ProductionOperationExecution $originExecution = null,
        ?callable $beforeStart = null,
    ): ProductionOperationExecution {
        return $this->scheduleLock->synchronized(fn () => $this->entityManager->wrapInTransaction(function () use ($plan, $actor, $command, $originExecution, $beforeStart): ProductionOperationExecution {
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            $this->lockOrderAndPlan($order, $plan);
            if ($originExecution !== null) {
                $this->entityManager->lock($originExecution, LockMode::PESSIMISTIC_WRITE);
            }

            $previousEvent = $this->eventRepository->findOneByCommandId($command->commandId);
            if ($previousEvent instanceof ServiceOrderTraceabilityEvent) {
                return $this->executionFromPreviousCommand($previousEvent, 'production.execution_started', $plan);
            }

            $this->assertExpectedOrderVersion($order, $command);
            $this->assertPlanCanStart($order, $plan, $actor, $originExecution);
            $this->inventoryAuthorizationGuard->assertOrderCanStartProduction($order);
            $this->assertMachineAvailable($plan);
            if ($beforeStart !== null) {
                $beforeStart($order, $plan, $actor);
            }

            $previousState = $order->traceabilityStateSnapshot();
            if ($order->getProductionStatus() === ProductionOrderStatus::WAITING_DESIGN) {
                $order->beginProduction();
            } elseif ($order->getProductionStatus() === ProductionOrderStatus::QUALITY_REWORK) {
                $order->resumeProductionAfterQualityRework();
            }

            $execution = new ProductionOperationExecution(
                $plan,
                $actor,
                $actor,
                $plan->nextExecutionAttemptNumber(),
                $originExecution,
            );
            $plan->addExecution($execution);
            $order->touchTraceability();
            $this->entityManager->persist($execution);

            // Se asigna el identificador dentro de la misma transacción antes
            // de registrar la auditoría; si cualquier paso posterior falla,
            // wrapInTransaction revierte también este flush.
            $this->entityManager->flush();

            $newState = $order->traceabilityStateSnapshot();
            $this->traceabilityRecorder->record(
                $order,
                'production.execution_started',
                $actor,
                $previousState,
                $newState,
                $plan->getServiceOrderItem(),
                $plan,
                $execution,
                null,
                [
                    'attempt_number' => $execution->getAttemptNumber(),
                    'operation_id' => $plan->getOperation()->getId(),
                ],
                $command->commandId,
                $command->commandId,
            );
            $this->auditLogger->record(
                $actor,
                'production.execution_started',
                'production_operation_execution',
                $execution->getId(),
                $previousState,
                $newState,
            );
            $this->entityManager->flush();

            return $execution;
        }));
    }

    public function pause(
        ProductionOperationExecution $execution,
        User $actor,
        ProductionExecutionCommand $command,
    ): void {
        $this->transitionOpenExecution(
            $execution,
            $actor,
            $command,
            'production.execution_paused',
            static fn (ProductionOperationExecution $current): ProductionOperationExecution => $current->pause((string) $command->note),
        );
    }

    public function resume(
        ProductionOperationExecution $execution,
        User $actor,
        ProductionExecutionCommand $command,
    ): void {
        $this->transitionOpenExecution(
            $execution,
            $actor,
            $command,
            'production.execution_resumed',
            static fn (ProductionOperationExecution $current): ProductionOperationExecution => $current->resume(),
        );
    }

    public function complete(
        ProductionOperationExecution $execution,
        User $actor,
        ProductionExecutionCommand $command,
        ?callable $beforeComplete = null,
    ): void {
        $this->scheduleLock->synchronized(fn () => $this->entityManager->wrapInTransaction(function () use ($execution, $actor, $command, $beforeComplete): void {
            $plan = $execution->getPlan();
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            $this->lockOrderAndPlan($order, $plan);
            $this->entityManager->refresh($execution, LockMode::PESSIMISTIC_WRITE);

            $previousEvent = $this->eventRepository->findOneByCommandId($command->commandId);
            if ($previousEvent instanceof ServiceOrderTraceabilityEvent) {
                $this->assertRepeatedCommand($previousEvent, 'production.execution_completed', $plan, $execution);

                return;
            }

            $this->assertExpectedOrderVersion($order, $command);
            $this->assertExecutionActor($execution, $actor);
            if ($plan->getBlockedReason() !== null) {
                throw new \DomainException('La operación tiene una incidencia abierta que debe resolverse antes de cerrarla.');
            }
            $previousState = $order->traceabilityStateSnapshot();
            if ($beforeComplete !== null) {
                $beforeComplete($order, $plan, $execution, $actor);
            }
            $execution->complete($command->note);
            $this->rescheduler->recalculateAfterExecutionChange($plan, $actor, 'production.execution_completed');

            $commitmentReconciliation = null;
            if ($this->allActiveOperationsAreCompleted($order)) {
                $commitmentReconciliation = $this->inventoryCommitmentReconciliationManager->releaseUnusedForCompletedOrder($order, $actor);
                $order->waitForQualityReview();
            }
            $order->touchTraceability();
            $newState = $order->traceabilityStateSnapshot();

            $this->traceabilityRecorder->record(
                $order,
                'production.execution_completed',
                $actor,
                $previousState,
                $newState,
                $plan->getServiceOrderItem(),
                $plan,
                $execution,
                $command->note,
                [
                    'attempt_number' => $execution->getAttemptNumber(),
                    'released_commitments' => $commitmentReconciliation?->releases ?? [],
                ],
                $command->commandId,
                $command->commandId,
            );
            $this->auditLogger->record(
                $actor,
                'production.execution_completed',
                'production_operation_execution',
                $execution->getId(),
                $previousState,
                $newState,
            );
            $this->entityManager->flush();
        }));
    }

    /** @param callable(ProductionOperationExecution): ProductionOperationExecution $transition */
    private function transitionOpenExecution(
        ProductionOperationExecution $execution,
        User $actor,
        ProductionExecutionCommand $command,
        string $eventType,
        callable $transition,
    ): void {
        $this->scheduleLock->synchronized(fn () => $this->entityManager->wrapInTransaction(function () use ($execution, $actor, $command, $eventType, $transition): void {
            $plan = $execution->getPlan();
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            $this->lockOrderAndPlan($order, $plan);
            $this->entityManager->refresh($execution, LockMode::PESSIMISTIC_WRITE);

            $previousEvent = $this->eventRepository->findOneByCommandId($command->commandId);
            if ($previousEvent instanceof ServiceOrderTraceabilityEvent) {
                $this->assertRepeatedCommand($previousEvent, $eventType, $plan, $execution);

                return;
            }

            $this->assertExpectedOrderVersion($order, $command);
            $this->assertExecutionActor($execution, $actor);
            if ($eventType === 'production.execution_resumed') {
                $this->inventoryAuthorizationGuard->assertOrderCanStartProduction($order);
            }
            if ($plan->getBlockedReason() !== null && str_starts_with($plan->getBlockedReason(), 'Incidencia:')) {
                $message = $eventType === 'production.execution_resumed'
                    ? 'Resuelve la incidencia antes de reanudar la operación.'
                    : 'La operación tiene una incidencia abierta y no puede pausarse de nuevo.';
                throw new \DomainException($message);
            }
            $previousState = $order->traceabilityStateSnapshot();
            if ($eventType === 'production.execution_resumed') { $this->assertMachineAvailable($plan); }
            $transition($execution);
            $this->rescheduler->recalculateAfterExecutionChange($plan, $actor, $eventType);
            $order->touchTraceability();
            $newState = $order->traceabilityStateSnapshot();

            $this->traceabilityRecorder->record(
                $order,
                $eventType,
                $actor,
                $previousState,
                $newState,
                $plan->getServiceOrderItem(),
                $plan,
                $execution,
                $command->note,
                ['attempt_number' => $execution->getAttemptNumber()],
                $command->commandId,
                $command->commandId,
            );
            $this->auditLogger->record(
                $actor,
                $eventType,
                'production_operation_execution',
                $execution->getId(),
                $previousState,
                $newState,
            );
            $this->entityManager->flush();
        }));
    }

    private function assertMachineAvailable(ServiceOrderOperationPlan $plan): void
    {
        if ($plan->getEquipment() === null) { return; }
        foreach ($this->planRepository->findForProductionQueue() as $other) {
            if ($other !== $plan && $other->getEquipment()?->getId() === $plan->getEquipment()->getId()
                && $other->getLatestExecution()?->getStatus() === \App\Enum\Production\ProductionOperationExecutionStatus::IN_PROGRESS) {
                throw new \DomainException('La máquina está ejecutando otro trabajo. Espera a que termine o sea pausado antes de iniciar o reanudar.');
            }
        }
    }

    private function lockOrderAndPlan(ServiceOrder $order, ServiceOrderOperationPlan $plan): void
    {
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($plan, LockMode::PESSIMISTIC_WRITE);
    }

    private function assertExpectedOrderVersion(ServiceOrder $order, ProductionExecutionCommand $command): void
    {
        if ($order->getTraceabilityVersion() !== $command->expectedOrderVersion) {
            throw new \DomainException('La orden cambió mientras se capturaba la operación. Actualiza la vista antes de continuar.');
        }
    }

    private function assertPlanCanStart(
        ServiceOrder $order,
        ServiceOrderOperationPlan $plan,
        User $actor,
        ?ProductionOperationExecution $originExecution,
    ): void {
        if (!$order->isPlanned()) {
            throw new \DomainException('La orden debe estar planificada antes de ejecutar una operación.');
        }
        if (!in_array($order->getProductionStatus(), [
            ProductionOrderStatus::WAITING_DESIGN,
            ProductionOrderStatus::IN_PRODUCTION,
            ProductionOrderStatus::QUALITY_REWORK,
        ], true)) {
            throw new \DomainException('La orden no está disponible para iniciar operaciones productivas.');
        }
        if (!$plan->isActive()) {
            throw new \DomainException('La operación planificada ya no está activa.');
        }
        $operationCode = $plan->getOperationSnapshot()['code'] ?? $plan->getOperation()->getCode();
        if (
            $operationCode !== 'PRE-DISENO'
            && $plan->getServiceOrderItem()->getDesignStatus() !== ServiceOrderItemDesignStatus::READY_FOR_PRINT
        ) {
            throw new \DomainException('Diseño debe liberar el arte y la captura de referencia antes de iniciar la siguiente etapa.');
        }
        if ($plan->getBlockedReason() !== null) {
            throw new \DomainException('La operación tiene un bloqueo que debe resolverse antes de iniciarla.');
        }
        if ($plan->hasOpenExecution()) {
            throw new \DomainException('La operación ya tiene un intento abierto.');
        }
        if ($originExecution !== null) {
            $rejection = $this->eventRepository->findLatestQualityRejectionForPlan($plan);
            if ($originExecution->getPlan() !== $plan) {
                throw new \DomainException('El intento origen no pertenece a la operación planificada.');
            }
            if ($order->getProductionStatus() !== ProductionOrderStatus::QUALITY_REWORK
                || $rejection?->getOperationExecution() !== $originExecution
                || $originExecution->isOpen()
                || $originExecution->getStatus() !== \App\Enum\Production\ProductionOperationExecutionStatus::COMPLETED
                || $plan->getLatestExecution() !== $originExecution
                || $plan->hasExecutionOrigin($originExecution)
            ) {
                throw new \DomainException('El intento origen debe tener una devolución de Calidad vigente para iniciar el retrabajo.');
            }
        } elseif ($order->getProductionStatus() === ProductionOrderStatus::QUALITY_REWORK) {
            throw new \DomainException('Selecciona el intento devuelto por Calidad para dejar vinculado el retrabajo.');
        } elseif ($plan->hasCompletedLatestExecution()) {
            throw new \DomainException('Una operación completada solo puede repetirse cuando Calidad autoriza un retrabajo.');
        }

        $assigned = $plan->getAssignedOperator();
        if ($assigned === null || !$this->sameUser($assigned, $actor)) {
            throw new \DomainException('Solo el operador asignado puede ejecutar esta operación.');
        }

        foreach ($plan->getServiceOrderItem()->getActiveOperationPlans() as $previousPlan) {
            if ($previousPlan->getSequenceNumber() >= $plan->getSequenceNumber()) {
                continue;
            }
            if (!$previousPlan->hasCompletedLatestExecution() || $previousPlan->hasOpenExecution()) {
                throw new \DomainException('La operación anterior de la partida debe completarse antes de continuar.');
            }
        }
    }

    private function assertExecutionActor(ProductionOperationExecution $execution, User $actor): void
    {
        if (!$this->sameUser($execution->getAssignedOperator(), $actor)) {
            throw new \DomainException('Solo el operador asignado puede actualizar este intento.');
        }
    }

    private function allActiveOperationsAreCompleted(ServiceOrder $order): bool
    {
        $hasOperations = false;
        foreach ($order->getItems() as $item) {
            foreach ($item->getActiveOperationPlans() as $plan) {
                $hasOperations = true;
                if (!$plan->hasCompletedLatestExecution() || $plan->hasOpenExecution() || $plan->getBlockedReason() !== null) {
                    return false;
                }
            }
        }

        return $hasOperations;
    }

    private function executionFromPreviousCommand(
        ServiceOrderTraceabilityEvent $event,
        string $eventType,
        ServiceOrderOperationPlan $plan,
    ): ProductionOperationExecution {
        $this->assertRepeatedCommand($event, $eventType, $plan);
        $execution = $event->getOperationExecution();
        if (!$execution instanceof ProductionOperationExecution) {
            throw new \DomainException('El comando previo no contiene el intento esperado.');
        }

        return $execution;
    }

    private function assertRepeatedCommand(
        ServiceOrderTraceabilityEvent $event,
        string $eventType,
        ServiceOrderOperationPlan $plan,
        ?ProductionOperationExecution $execution = null,
    ): void {
        if ($event->getEventType() !== $eventType || !$this->samePlan($event->getOperationPlan(), $plan)) {
            throw new \DomainException('El identificador de comando ya fue usado por una acción distinta.');
        }
        if ($execution !== null && !$this->sameExecution($event->getOperationExecution(), $execution)) {
            throw new \DomainException('El identificador de comando no corresponde al intento indicado.');
        }
    }

    private function sameUser(User $left, User $right): bool
    {
        return $left === $right || ($left->getId() !== null && $left->getId() === $right->getId());
    }

    private function samePlan(?ServiceOrderOperationPlan $left, ServiceOrderOperationPlan $right): bool
    {
        return $left === $right || ($left?->getId() !== null && $left->getId() === $right->getId());
    }

    private function sameExecution(?ProductionOperationExecution $left, ProductionOperationExecution $right): bool
    {
        return $left === $right || ($left?->getId() !== null && $left->getId() === $right->getId());
    }
}
