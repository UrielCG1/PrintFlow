<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Orders\ServiceOrderTraceabilityEvent;
use App\Entity\Users\User;
use App\Repository\Orders\ServiceOrderTraceabilityEventRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderTraceabilityRecorder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Registra y mantiene bloqueos operativos usando el motivo existente del plan. */
final class ProductionIncidentManager
{
    private const PREFIX = 'Incidencia:';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceOrderTraceabilityEventRepository $eventRepository,
        private readonly ServiceOrderTraceabilityRecorder $traceabilityRecorder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function open(
        ServiceOrderOperationPlan $plan,
        User $actor,
        ProductionExecutionCommand $command,
    ): void {
        $this->change($plan, $actor, $command, true);
    }

    public function resolve(
        ServiceOrderOperationPlan $plan,
        User $actor,
        ProductionExecutionCommand $command,
    ): void {
        $this->change($plan, $actor, $command, false);
    }

    private function change(
        ServiceOrderOperationPlan $plan,
        User $actor,
        ProductionExecutionCommand $command,
        bool $opening,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($plan, $actor, $command, $opening): void {
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);

            $eventType = $opening ? 'production.issue_opened' : 'production.issue_resolved';
            $previousEvent = $this->eventRepository->findOneByCommandId($command->commandId);
            if ($previousEvent instanceof ServiceOrderTraceabilityEvent) {
                if ($previousEvent->getEventType() !== $eventType || $previousEvent->getOperationPlan() !== $plan) {
                    throw new \DomainException('El identificador de comando ya se usó en otra acción.');
                }

                return;
            }
            if ($order->getTraceabilityVersion() !== $command->expectedOrderVersion) {
                throw new \DomainException('La orden cambió. Actualiza la vista antes de registrar la incidencia.');
            }
            if (!$plan->isActive() || !$order->isPlanned()) {
                throw new \DomainException('Solo se pueden gestionar incidencias de operaciones activas y planificadas.');
            }
            $reason = $command->note;
            if ($reason === null || mb_strlen($reason) < 5 || mb_strlen($reason) > 220) {
                throw new \InvalidArgumentException('El motivo de la incidencia debe tener entre 5 y 220 caracteres.');
            }

            $currentReason = $plan->getBlockedReason();
            if ($opening) {
                if ($currentReason !== null) {
                    throw new \DomainException('La operación ya tiene un bloqueo o una causa de planificación pendiente.');
                }
                $latest = $plan->getLatestExecution();
                if ($latest?->getStatus() === \App\Enum\Production\ProductionOperationExecutionStatus::IN_PROGRESS) {
                    throw new \DomainException('Pausa primero la operación activa; una incidencia no interrumpe la máquina en marcha.');
                }
                $plan->setBlockedReason(self::PREFIX.' '.$reason);
            } else {
                if ($currentReason === null || !str_starts_with($currentReason, self::PREFIX)) {
                    throw new \DomainException('La operación no tiene una incidencia operativa pendiente de resolver.');
                }
                $plan->setBlockedReason(null);
            }

            $previousState = [
                'order' => $order->traceabilityStateSnapshot(),
                'blocked_reason' => $currentReason,
            ];
            $order->touchTraceability();
            $newState = [
                'order' => $order->traceabilityStateSnapshot(),
                'blocked_reason' => $plan->getBlockedReason(),
            ];
            $this->traceabilityRecorder->record(
                $order,
                $eventType,
                $actor,
                $previousState,
                $newState,
                $plan->getServiceOrderItem(),
                $plan,
                $plan->getLatestExecution(),
                $reason,
                ['operation_code' => $plan->getOperationSnapshot()['code'] ?? $plan->getOperation()->getCode()],
                $command->commandId,
                $command->commandId,
            );
            $this->auditLogger->record($actor, $eventType, 'service_order_operation_plan', $plan->getId(), $previousState, $newState);
            $this->entityManager->flush();
        });
    }
}
