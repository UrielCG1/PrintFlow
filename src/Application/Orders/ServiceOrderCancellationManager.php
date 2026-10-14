<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Application\Inventory\InventoryCommitmentReconciliationManager;
use App\Application\Production\ProductionScheduleLock;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Production\ProductionOperationExecutionRepository;
use App\Repository\Production\ProductionReservationRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderTraceabilityRecorder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Cancela una orden sin borrar su evidencia ni devolver consumos ya realizados. */
final class ServiceOrderCancellationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceOrderOperationPlanRepository $planRepository,
        private readonly ProductionOperationExecutionRepository $executionRepository,
        private readonly ProductionReservationRepository $reservationRepository,
        private readonly InventoryCommitmentReconciliationManager $commitmentReconciliationManager,
        private readonly ProductionScheduleLock $scheduleLock,
        private readonly ServiceOrderTraceabilityRecorder $traceabilityRecorder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function cancel(ServiceOrder $submittedOrder, string $reason, User $actor): ServiceOrderCancellationResult
    {
        $reason = $this->normalizeReason($reason);

        return $this->scheduleLock->synchronized(fn (): ServiceOrderCancellationResult => $this->entityManager->wrapInTransaction(
            function () use ($submittedOrder, $reason, $actor): ServiceOrderCancellationResult {
                $this->entityManager->refresh($submittedOrder, LockMode::PESSIMISTIC_WRITE);
                if (!$submittedOrder->canCancelProduction()) {
                    throw new \DomainException('La orden no puede cancelarse porque ya fue liberada o tiene una entrega en curso.');
                }

                $previous = $submittedOrder->traceabilityStateSnapshot();
                $cancelledExecutions = 0;
                foreach ($this->planRepository->findActiveForServiceOrder($submittedOrder) as $plan) {
                    $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
                    $openExecution = $this->executionRepository->findOpenForPlanForUpdate($plan);
                    if ($openExecution === null) {
                        continue;
                    }

                    $openExecution->cancel($this->executionCancellationReason($reason));
                    ++$cancelledExecutions;
                }

                $releasedReservations = 0;
                foreach ($this->reservationRepository->findActiveForServiceOrder($submittedOrder) as $reservation) {
                    $this->entityManager->lock($reservation, LockMode::PESSIMISTIC_WRITE);
                    if (!$reservation->isActive()) {
                        continue;
                    }

                    $reservation->deactivate();
                    ++$releasedReservations;
                }

                $reconciliation = $this->commitmentReconciliationManager->releaseUnusedForCancelledOrder($submittedOrder, $actor);
                $submittedOrder->cancelProduction()->touchTraceability();
                $current = $submittedOrder->traceabilityStateSnapshot();

                $this->traceabilityRecorder->record(
                    $submittedOrder,
                    'production.order_cancelled',
                    $actor,
                    $previous,
                    $current,
                    null,
                    null,
                    null,
                    $reason,
                    [
                        'cancelled_open_executions' => $cancelledExecutions,
                        'released_machine_reservations' => $releasedReservations,
                        'released_commitments' => $reconciliation->releases,
                    ],
                );
                $this->auditLogger->record(
                    $actor,
                    'service_order.production_cancelled',
                    'service_order',
                    $submittedOrder->getId(),
                    $previous,
                    $current + [
                        'reason' => $reason,
                        'cancelled_open_executions' => $cancelledExecutions,
                        'released_machine_reservations' => $releasedReservations,
                        'released_commitments' => $reconciliation->releases,
                    ],
                );
                $this->entityManager->flush();

                return new ServiceOrderCancellationResult(
                    $cancelledExecutions,
                    $releasedReservations,
                    $reconciliation->releases,
                );
            },
        ));
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('El motivo de cancelacion debe tener entre 5 y 500 caracteres.');
        }

        return $reason;
    }

    private function executionCancellationReason(string $reason): string
    {
        return mb_substr('Orden cancelada: '.$reason, 0, 500);
    }
}
