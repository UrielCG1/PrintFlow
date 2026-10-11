<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Equipment\Equipment;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionReservation;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionPriority;
use App\Enum\Orders\ProductionEstimateKind;
use App\Enum\Production\ProductionOperationExecutionStatus;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Production\ProductionCalendarExceptionRepository;
use App\Repository\Production\ProductionReservationRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Previews and atomically applies a priority ordered production queue. */
final class ProductionPriorityRescheduler
{
    private const TIMEZONE = 'America/Mexico_City';
    private const HORIZON_DAYS = 730;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductionReservationRepository $reservationRepository,
        private readonly ServiceOrderOperationPlanRepository $planRepository,
        private readonly ProductionCalendarExceptionRepository $exceptionRepository,
        private readonly ProductionScheduleCalculator $scheduleCalculator,
        private readonly ProductionCommitmentAnalyzer $commitmentAnalyzer,
        private readonly ProductionScheduleLock $scheduleLock,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @return array<string, mixed> */
    public function previewPriorityChange(
        ServiceOrderItem $item,
        ProductionPriority $priority,
        string $reason,
    ): array {
        $reason = $this->validateReason($reason);
        if ($item->getPriority() === $priority) {
            throw new \DomainException('La partida ya tiene esa prioridad.');
        }

        return $this->simulate($item, $priority, $reason, new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)));
    }

    /** @return array<string, mixed> */
    public function confirmPriorityChange(
        ServiceOrderItem $item,
        ProductionPriority $priority,
        string $reason,
        string $expectedFingerprint,
        User $actor,
    ): array {
        $reason = $this->validateReason($reason);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint) !== 1) {
            throw new \DomainException('La vista previa venció. Vuelve a calcular el impacto.');
        }

        $lockAcquired = $this->scheduleLock->acquire();
        try {
            return $this->entityManager->wrapInTransaction(function () use ($item, $priority, $reason, $expectedFingerprint, $actor): array {
                $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
                $this->lockQueueRows($now);
                $simulation = $this->simulate($item, $priority, $reason, $now);
                if (!hash_equals($expectedFingerprint, $simulation['fingerprint'])) {
                    throw new \DomainException('La agenda cambió después de la vista previa. Vuelve a calcular el impacto.');
                }
                if ($item->getPriority() === $priority) {
                    throw new \DomainException('La prioridad de esta partida ya fue actualizada.');
                }

                $order = $item->getServiceOrder();
                $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->lock($item, LockMode::PESSIMISTIC_WRITE);
                $oldPriority = $item->getPriority();
                $item->setPriority($priority);

                $applied = [];
                foreach ($simulation['schedule'] as $planId => $segments) {
                    $plan = $simulation['plans'][(int) $planId]['plan'];
                    $old = $simulation['plans'][(int) $planId]['current_segments'];
                    $new = $this->segmentSnapshot($segments);
                    if ($old === $new) {
                        continue;
                    }
                    $this->applySegments($plan, $segments, $actor);
                    $this->auditLogger->record(
                        actor: $actor,
                        action: 'production.priority_rescheduled',
                        entityType: 'service_order_operation_plan',
                        entityId: $plan->getId(),
                        oldValues: ['segments' => $old],
                        newValues: [
                            'segments' => $new,
                            'reason' => $reason,
                            'schedule_version' => $simulation['fingerprint'],
                        ],
                    );
                    $applied[] = (int) $planId;
                }

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'production.item_priority_changed',
                    entityType: 'service_order_item',
                    entityId: $item->getId(),
                    oldValues: ['priority' => $oldPriority->value],
                    newValues: [
                        'priority' => $priority->value,
                        'reason' => $reason,
                        'schedule_version' => $simulation['fingerprint'],
                        'rescheduled_plan_ids' => $applied,
                    ],
                );
                $this->entityManager->flush();

                return [
                    'changed_plans' => count($applied),
                    'risks' => $simulation['risks'],
                ];
            });
        } finally {
            $this->scheduleLock->release($lockAcquired);
        }
    }

    public function setSchedulePinned(ServiceOrderOperationPlan $plan, bool $pinned, User $actor): void
    {
        $lockAcquired = $this->scheduleLock->acquire();
        try {
            $this->entityManager->wrapInTransaction(function () use ($plan, $pinned, $actor): void {
                $order = $plan->getServiceOrderItem()->getServiceOrder();
                $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
                if (!$plan->isActive()) {
                    throw new \DomainException('No se puede fijar una operación retirada de la ruta.');
                }
                if (!$plan->hasActiveReservations()) {
                    throw new \DomainException('Reserva primero la operación para poder fijarla en la agenda.');
                }
                $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
                $hasFutureReservation = false;
                foreach ($plan->getReservations() as $reservation) {
                    if (!$reservation->isActive()) {
                        continue;
                    }
                    if ($reservation->getStartsAt() <= $now) {
                        throw new \DomainException('Una operación iniciada ya queda protegida; no puede fijarse o liberarse desde aquí.');
                    }
                    $hasFutureReservation = true;
                }
                if (!$hasFutureReservation) {
                    throw new \DomainException('Solo se pueden fijar reservas futuras sin iniciar.');
                }
                if ($plan->isSchedulePinned() === $pinned) {
                    return;
                }

                $old = $plan->isSchedulePinned();
                $plan->setSchedulePinned($pinned);
                $this->auditLogger->record(
                    actor: $actor,
                    action: $pinned ? 'production.reservation_pinned' : 'production.reservation_unpinned',
                    entityType: 'service_order_operation_plan',
                    entityId: $plan->getId(),
                    oldValues: ['is_schedule_pinned' => $old],
                    newValues: ['is_schedule_pinned' => $pinned],
                );
                $this->entityManager->flush();
            });
        } finally {
            $this->scheduleLock->release($lockAcquired);
        }
    }

    /** El llamador conserva el bloqueo global y la transacción de la transición. */
    public function recalculateAfterExecutionChange(ServiceOrderOperationPlan $target, User $actor, string $eventType): void
    {
        $now = \Symfony\Component\Clock\Clock::get()->now()->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $this->lockQueueRows($now);
        $item = $target->getServiceOrderItem();
        $simulation = $this->simulate($item, $item->getPriority(), $eventType, $now, $target);
        if ($eventType === 'production.execution_resumed' && $target->getEquipment() !== null) {
            $first = $simulation['schedule'][(int) $target->getId()][0]['start'] ?? null;
            if ($first === null || $first > $this->ceilToNextMinute($now)) {
                throw new \DomainException('La máquina no puede reanudarse ahora: revisa su jornada, trabajos activos y reservas fijadas. El intento permanece pausado.');
            }
        }
        foreach ($simulation['schedule'] as $planId => $segments) {
            $record = $simulation['plans'][(int) $planId];
            $plan = $record['plan'];
            $before = $record['current_segments'];
            $futureBefore = array_values(array_filter($record['rows'], static fn (ProductionReservation $row): bool => $row->getEndsAt() > $now));
            if ($this->segmentSnapshot(array_map(static fn (ProductionReservation $row): array => [
                'start' => $row->getStartsAt(), 'end' => $row->getEndsAt(),
            ], $futureBefore)) === $this->segmentSnapshot($segments)) {
                continue;
            }
            // Las reservas pasadas se conservan. La parte pendiente se retira y
            // se crea de nuevo, dejando visibles tanto los intentos como su agenda anterior.
            foreach ($futureBefore as $row) {
                if ($row->getStartsAt() < $now) {
                    $row->reschedule($row->getStartsAt(), $now);
                } else {
                    $row->deactivate();
                }
            }
            $maxSegment = 0;
            foreach ($plan->getReservations() as $row) { $maxSegment = max($maxSegment, $row->getSegmentNumber()); }
            foreach ($segments as $segment) {
                $row = (new ProductionReservation())->setPlan($plan)->setEquipment($plan->getEquipment())
                    ->setCreatedBy($actor)->setSegmentNumber(++$maxSegment)
                    ->setStartsAt($segment['start'])->setEndsAt($segment['end']);
                $plan->addReservation($row);
                $this->entityManager->persist($row);
            }
            $record['order']->touchTraceability();
            $this->auditLogger->record($actor, 'production.execution_schedule_recalculated', 'service_order_operation_plan', $plan->getId(),
                ['segments' => $before], ['segments' => $this->segmentSnapshot($segments), 'trigger' => $eventType]);
        }
    }

    /** @return array<string, mixed> */
    private function simulate(
        ServiceOrderItem $priorityTarget,
        ProductionPriority $targetPriority,
        string $reason,
        \DateTimeImmutable $now,
        ?ServiceOrderOperationPlan $executionTarget = null,
    ): array {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $horizon = $now->modify('+'.self::HORIZON_DAYS.' days');
        $records = [];
        foreach ($this->reservationRepository->findActiveForProductionQueue($now, $horizon) as $reservation) {
            $plan = $reservation->getPlan();
            $id = (int) $plan->getId();
            $records[$id] ??= ['plan' => $plan];
        }

        // También entran pasos suspendidos que ya tuvieron agenda: al resolver
        // una incidencia deben recuperar su reserva sin una captura manual nueva.
        foreach ($this->planRepository->findForProductionQueue() as $plan) {
            if ($plan->getConditionApplied() !== false && !$plan->getReservations()->isEmpty()) {
                $records[(int) $plan->getId()] ??= ['plan' => $plan];
            }
        }

        if ($executionTarget !== null) {
            $affectedItems = [(int) $priorityTarget->getId() => true];
            $affectedEquipment = [];
            if ($executionTarget->getEquipment() !== null) { $affectedEquipment[(int) $executionTarget->getEquipment()->getId()] = true; }
            for ($pass = 0; $pass < count($records); ++$pass) {
                $previousSize = count($affectedItems) + count($affectedEquipment);
                foreach ($records as $record) {
                    $plan = $record['plan'];
                    $itemId = (int) $plan->getServiceOrderItem()->getId();
                    $equipmentId = (int) $plan->getEquipment()?->getId();
                    if (isset($affectedItems[$itemId]) || isset($affectedEquipment[$equipmentId])) {
                        $affectedItems[$itemId] = true;
                        if ($equipmentId !== 0) { $affectedEquipment[$equipmentId] = true; }
                    }
                }
                if ($previousSize === count($affectedItems) + count($affectedEquipment)) { break; }
            }
            // Solo se reorganizan máquinas y partidas relacionadas con el cambio.
            $records = array_filter($records, static fn (array $record): bool => isset($affectedItems[(int) $record['plan']->getServiceOrderItem()->getId()]));
        }

        foreach ($records as $id => &$record) {
            /** @var ServiceOrderOperationPlan $plan */
            $plan = $record['plan'];
            $activeRows = array_values(array_filter(
                $plan->getReservations()->toArray(),
                static fn (ProductionReservation $row): bool => $row->isActive(),
            ));
            usort($activeRows, static fn (ProductionReservation $left, ProductionReservation $right): int => $left->getSegmentNumber() <=> $right->getSegmentNumber());
            $record['rows'] = $activeRows;
            $record['current_segments'] = $this->segmentSnapshot(array_map(
                static fn (ProductionReservation $row): array => ['start' => $row->getStartsAt(), 'end' => $row->getEndsAt()],
                $activeRows,
            ));
            $record['equipment'] = $plan->getEquipment();
            $record['item'] = $plan->getServiceOrderItem();
            $record['order'] = $record['item']->getServiceOrder();
            $execution = $plan->getLatestExecution();
            $record['completed'] = $plan->hasCompletedLatestExecution();
            $record['suspended'] = $plan->getBlockedReason() !== null
                || $execution?->getStatus() === ProductionOperationExecutionStatus::PAUSED;
            $record['started'] = $execution?->getStatus() === ProductionOperationExecutionStatus::IN_PROGRESS;
            foreach ($activeRows as $row) {
                if ($executionTarget === null && $row->getStartsAt() <= $now) {
                    $record['started'] = true;
                    break;
                }
            }
            $record['fixed'] = $plan->isSchedulePinned()
                || ($record['started'] && $plan !== $executionTarget)
                || !$plan->isActive()
                || $plan->getEquipment() === null
                || $plan->getEstimatedMinutes() === null;
            $record['future_rows'] = array_values(array_filter(
                $activeRows,
                static fn (ProductionReservation $row): bool => $row->getEndsAt() > $now && $row->getStartsAt() < $horizon,
            ));
            $record['equipment_id'] = $plan->getEquipment()?->getId();
            $record['order_id'] = $record['order']->getId();
            $record['priority'] = $record['item'] === $priorityTarget ? $targetPriority : $record['item']->getPriority();
            $record['priority_target'] = $record['item'] === $priorityTarget;
            $record['commitment_date'] = $record['order']->getCommitmentDate()?->format('Y-m-d');
            $record['order_created_at'] = $record['order']->getCreatedAt()->format('Y-m-d\TH:i:s.uP');
            $record['sequence'] = $plan->getSequenceNumber();
            $record['current_rows'] = $record['current_segments'];
            $record['remaining_minutes'] = $execution !== null && $execution->isOpen()
                ? max(1, (int) ceil((($plan->getEstimatedMinutes() ?? 0) * 60 - $execution->getActiveSecondsAt($now)) / 60))
                : $plan->getEstimatedMinutes();
        }
        unset($record);

        // Propaga la falta de fecha a pasos dependientes, incluso en otra máquina.
        for ($pass = 0; $pass < count($records); ++$pass) {
            $changed = false;
            foreach ($records as &$record) {
                $previous = $this->planRepository->findPreviousActivePlan($record['plan']);
                if (!$record['completed'] && !$record['suspended'] && $previous !== null
                    && ($records[(int) $previous->getId()]['suspended'] ?? false)) {
                    $record['suspended'] = true;
                    $changed = true;
                }
            }
            unset($record);
            if (!$changed) { break; }
        }

        $equipmentById = [];
        $orderById = [];
        foreach ($records as $record) {
            if ($record['equipment'] instanceof Equipment) {
                $equipmentById[(int) $record['equipment']->getId()] = $record['equipment'];
            }
            $orderById[(int) $record['order_id']] = $record['order'];
        }
        if ($priorityTarget->getId() !== null) {
            $orderById[(int) $priorityTarget->getServiceOrder()->getId()] = $priorityTarget->getServiceOrder();
        }
        ksort($equipmentById);
        ksort($orderById);

        $exceptionsByEquipment = [];
        $exceptionFacts = [];
        foreach ($equipmentById as $equipmentId => $equipment) {
            $rows = $this->exceptionRepository->findActiveForEquipmentBetween($equipment, $now, $horizon);
            $exceptionsByEquipment[$equipmentId] = array_map(static fn ($row): array => [
                'type' => $row->getType(),
                'start' => $row->getStartsAt(),
                'end' => $row->getEndsAt(),
            ], $rows);
            foreach ($rows as $exception) {
                $exceptionFacts[(int) $exception->getId()] = [
                    'id' => $exception->getId(),
                    'equipment_id' => $exception->getEquipment()?->getId(),
                    'type' => $exception->getType()->value,
                    'start' => $exception->getStartsAt()->format(\DATE_ATOM),
                    'end' => $exception->getEndsAt()->format(\DATE_ATOM),
                ];
            }
        }
        ksort($exceptionFacts);

        $fingerprintFacts = [];
        foreach ($records as $id => $record) {
            $fingerprintFacts[] = [
                'plan_id' => $id,
                'item_id' => $record['item']->getId(),
                'order_id' => $record['order_id'],
                'current_priority' => $record['item']->getPriority()->value,
                'priority' => $record['priority']->value,
                'commitment_date' => $record['commitment_date'],
                'order_created_at' => $record['order_created_at'],
                'sequence' => $record['sequence'],
                'equipment_id' => $record['equipment_id'],
                'equipment_selectable' => $record['equipment'] instanceof Equipment && $record['equipment']->isSelectableForFutureExecution(),
                'estimated_minutes' => $record['plan']->getEstimatedMinutes(),
                'is_active' => $record['plan']->isActive(),
                'is_schedule_pinned' => $record['plan']->isSchedulePinned(),
                'blocked_reason' => $record['plan']->getBlockedReason(),
                'execution_status' => $record['plan']->getLatestExecution()?->getStatus()->value,
                'execution_id' => $record['plan']->getLatestExecution()?->getId(),
                'traceability_version' => $record['order']->getTraceabilityVersion(),
                'segments' => $record['current_segments'],
            ];
        }
        usort($fingerprintFacts, static fn (array $left, array $right): int => $left['plan_id'] <=> $right['plan_id']);
        $scheduleAnchor = $this->ceilToNextMinute($now);

        $busyByEquipment = [];
        $completionByPlan = [];
        $pending = [];
        $schedule = [];
        foreach ($records as $id => $record) {
            $rows = $record['future_rows'];
            if ($record['completed']) {
                $completionByPlan[(int) $id] = $record['plan']->getLatestExecution()->getCompletedAt();
                if ($executionTarget !== null && $rows !== []) { $schedule[(int) $id] = []; }
                continue;
            }
            if ($record['suspended'] && !$record['plan']->isSchedulePinned() && !$record['started']) {
                if ($executionTarget !== null) { $schedule[(int) $id] = []; }
                continue;
            }
            if ($rows === [] && $record['started'] && $record['fixed'] && $record['equipment_id'] !== null) {
                $end = $now->modify('+'.(int) $record['remaining_minutes'].' minutes');
                $busyByEquipment[(int) $record['equipment_id']][] = ['start' => $now, 'end' => $end];
                $completionByPlan[(int) $id] = $end;
            }
            if ($rows === [] && ($executionTarget === null || $record['fixed'])) { continue; }
            $latestEnd = null;
            foreach ($rows as $row) {
                if ($latestEnd === null || $row->getEndsAt() > $latestEnd) {
                    $latestEnd = $row->getEndsAt();
                }
            }
            if ($record['fixed']) {
                $completionByPlan[(int) $id] = $latestEnd;
                foreach ($rows as $row) {
                    $busyByEquipment[(int) $row->getEquipment()->getId()][] = [
                        'start' => $row->getStartsAt(),
                        'end' => $row->getEndsAt(),
                    ];
                }
            } elseif (!$record['suspended']) {
                $pending[(int) $id] = $record;
            }
        }

        while ($pending !== []) {
            $ready = [];
            foreach ($pending as $id => $record) {
                $previous = $this->planRepository->findPreviousActivePlan($record['plan']);
                if ($previous === null) {
                    $ready[$id] = ['record' => $record, 'not_before' => $scheduleAnchor];
                    continue;
                }
                $previousId = (int) $previous->getId();
                if (array_key_exists($previousId, $pending)) {
                    continue;
                }
                if (array_key_exists($previousId, $completionByPlan)) {
                    $previousEnd = $completionByPlan[$previousId];
                    $ready[$id] = ['record' => $record, 'not_before' => $previousEnd > $scheduleAnchor ? $previousEnd : $scheduleAnchor];
                    continue;
                }

                $previousEnd = $previous->hasCompletedLatestExecution()
                    ? $previous->getLatestExecution()->getCompletedAt()
                    : $this->latestActiveEnd($previous);
                if ($previousEnd === null) {
                    throw new \DomainException('No se puede reprogramar: falta una reserva de la operación anterior.');
                }
                $ready[$id] = ['record' => $record, 'not_before' => $previousEnd > $scheduleAnchor ? $previousEnd : $scheduleAnchor];
            }
            if ($ready === []) {
                throw new \DomainException('No se pudo resolver la secuencia de operaciones para reprogramar. Revisa las rutas de las partidas.');
            }

            uasort($ready, static function (array $left, array $right): int {
                /** @var ServiceOrderOperationPlan $leftPlan */
                $leftPlan = $left['record']['plan'];
                /** @var ServiceOrderOperationPlan $rightPlan */
                $rightPlan = $right['record']['plan'];
                $leftOrder = $leftPlan->getServiceOrderItem()->getServiceOrder();
                $rightOrder = $rightPlan->getServiceOrderItem()->getServiceOrder();
                $resuming = ($right['record']['started'] ? 1 : 0) <=> ($left['record']['started'] ? 1 : 0);
                if ($resuming !== 0) { return $resuming; }
                $priority = $right['record']['priority']->weight() <=> $left['record']['priority']->weight();
                if ($priority !== 0) {
                    return $priority;
                }
                $leftCommitment = $leftOrder->getCommitmentDate()?->format('Y-m-d') ?? '9999-12-31';
                $rightCommitment = $rightOrder->getCommitmentDate()?->format('Y-m-d') ?? '9999-12-31';
                $commitment = $leftCommitment <=> $rightCommitment;
                if ($commitment !== 0) {
                    return $commitment;
                }
                $created = $leftOrder->getCreatedAt() <=> $rightOrder->getCreatedAt();
                if ($created !== 0) {
                    return $created;
                }
                $order = $leftOrder->getId() <=> $rightOrder->getId();
                if ($order !== 0) {
                    return $order;
                }
                $item = $leftPlan->getServiceOrderItem()->getId() <=> $rightPlan->getServiceOrderItem()->getId();
                return $item !== 0 ? $item : ($leftPlan->getSequenceNumber() <=> $rightPlan->getSequenceNumber());
            });

            foreach ($ready as $id => $entry) {
                $record = $entry['record'];
                /** @var Equipment $equipment */
                $equipment = $record['equipment'];
                $segments = $this->scheduleCalculator->segments(
                    $entry['not_before'],
                    (int) $record['remaining_minutes'],
                    $busyByEquipment[(int) $equipment->getId()] ?? [],
                    $exceptionsByEquipment[(int) $equipment->getId()] ?? [],
                );
                $this->assertCapacityCovers($equipment, $record['plan'], $segments);
                $schedule[$id] = $segments;
                foreach ($segments as $segment) {
                    $busyByEquipment[(int) $equipment->getId()][] = $segment;
                }
                $completionByPlan[$id] = $segments[array_key_last($segments)]['end'];
                unset($pending[$id]);
            }
        }

        foreach ($records as $record) {
            if (!$record['fixed'] || $record['suspended'] || $record['completed'] || $record['future_rows'] === []) { continue; }
            $previous = $this->planRepository->findPreviousActivePlan($record['plan']);
            $previousEnd = $previous === null ? null : ($completionByPlan[(int) $previous->getId()] ?? null);
            $firstStart = min(array_map(static fn (ProductionReservation $row): \DateTimeImmutable => $row->getStartsAt(), $record['future_rows']));
            if ($previousEnd !== null && $previousEnd > $firstStart) {
                throw new \DomainException('El nuevo horario no cabe antes de una reserva fijada o activa de la misma ruta. El jefe debe revisar esa reserva antes de continuar.');
            }
        }

        $proposedSegments = [];
        foreach ($schedule as $planId => $segments) {
            $proposedSegments[(int) $planId] = $this->segmentSnapshot($segments);
        }
        ksort($proposedSegments);
        $fingerprint = hash('sha256', json_encode([
            'target_item_id' => $priorityTarget->getId(),
            'target_priority' => $targetPriority->value,
            'reason' => $reason,
            'plans' => $fingerprintFacts,
            'exceptions' => array_values($exceptionFacts),
            'proposed_segments' => $proposedSegments,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $risks = $this->riskChanges($priorityTarget->getServiceOrder(), $orderById, $records, $schedule);
        $changes = [];
        foreach ($schedule as $planId => $segments) {
            $record = $records[(int) $planId];
            $newSegments = $this->segmentSnapshot($segments);
            if ($record['current_segments'] === $newSegments) {
                continue;
            }
            $changes[] = [
                'plan_id' => (int) $planId,
                'order_folio' => $record['order']->getFolio(),
                'line_number' => $record['item']->getDisplayLineNumber(),
                'operation' => $record['plan']->getOperationSnapshot()['name'] ?? $record['plan']->getOperation()->getName(),
                'machine' => $record['plan']->getEquipmentSnapshot()['code'] ?? $record['plan']->getEquipment()?->getCode(),
                'priority' => $record['priority']->label(),
                'old_segments' => $record['current_segments'],
                'new_segments' => $newSegments,
                'started' => false,
                'pinned' => false,
            ];
        }

        return [
            'fingerprint' => $fingerprint,
            'priority' => $targetPriority,
            'reason' => $reason,
            'changes' => $changes,
            'changed_plan_count' => count($changes),
            'risks' => $risks,
            'schedule' => $schedule,
            'plans' => $records,
            'generated_at' => $now->setTimezone($timezone),
        ];
    }

    /** @param array<int, ServiceOrder> $orderById @param array<int, array<string, mixed>> $records @param array<int, list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $schedule
     *  @return list<array<string, mixed>> */
    private function riskChanges(ServiceOrder $targetOrder, array $orderById, array $records, array $schedule): array
    {
        $orderById[(int) $targetOrder->getId()] = $targetOrder;
        $beforeEnds = [];
        $afterEnds = [];
        foreach ($records as $planId => $record) {
            $end = $this->latestActiveEnd($record['plan']);
            if ($end !== null) {
                $beforeEnds[$planId] = $end;
                $afterEnds[$planId] = $end;
            }
        }
        foreach ($schedule as $planId => $segments) {
            if ($segments === []) {
                unset($afterEnds[$planId]);
            } else {
                $afterEnds[$planId] = $segments[array_key_last($segments)]['end'];
            }
        }

        $risks = [];
        foreach ($orderById as $orderId => $order) {
            $beforeFinish = $this->orderFinish($order, $beforeEnds);
            $afterFinish = $this->orderFinish($order, $afterEnds);
            $rangeDates = array_values(array_filter([$beforeFinish, $afterFinish, $order->getCommitmentDate()]));
            $holidays = [];
            if ($rangeDates !== []) {
                $timezone = new \DateTimeZone(self::TIMEZONE);
                $rangeDates = array_map(static fn (\DateTimeImmutable $date): \DateTimeImmutable => $date->setTimezone($timezone), $rangeDates);
                $start = min($rangeDates)->setTime(0, 0);
                $end = max($rangeDates)->modify('+5 days')->setTime(23, 59, 59);
                $holidays = array_map(
                    static fn ($exception): array => ['start' => $exception->getStartsAt(), 'end' => $exception->getEndsAt()],
                    $this->exceptionRepository->findGlobalHolidaysBetween($start, $end),
                );
            }
            $before = $this->commitmentAnalyzer->analyze($beforeFinish, $order->getCommitmentDate(), $holidays);
            $after = $this->commitmentAnalyzer->analyze($afterFinish, $order->getCommitmentDate(), $holidays);
            $finishUnchanged = $beforeFinish === null
                ? $afterFinish === null
                : ($afterFinish !== null && $beforeFinish->getTimestamp() === $afterFinish->getTimestamp());
            if ($finishUnchanged && (int) $orderId !== (int) $targetOrder->getId()) {
                continue;
            }
            $risks[] = [
                'order_id' => (int) $orderId,
                'order_folio' => $order->getFolio(),
                'commitment_date' => $order->getCommitmentDate(),
                'before_finish' => $beforeFinish,
                'after_finish' => $afterFinish,
                'suggested_delivery' => $after['suggested_delivery'],
                'before_slack_business_days' => $before['slack_business_days'],
                'after_slack_business_days' => $after['slack_business_days'],
                'before_risk' => $before['risk'],
                'after_risk' => $after['risk'],
                'before_cause' => $before['risk_cause'],
                'after_cause' => $after['risk_cause'],
            ];
        }

        return $risks;
    }

    /** @param array<int, \DateTimeImmutable> $ends */
    private function orderFinish(ServiceOrder $order, array $ends): ?\DateTimeImmutable
    {
        $finish = null;
        foreach ($order->getItems() as $item) {
            $plans = array_values(array_filter(
                $item->getActiveOperationPlans(),
                static fn (ServiceOrderOperationPlan $plan): bool => $plan->getConditionApplied() !== false,
            ));
            usort($plans, static fn (ServiceOrderOperationPlan $left, ServiceOrderOperationPlan $right): int => $left->getSequenceNumber() <=> $right->getSequenceNumber());
            $last = $plans !== [] ? $plans[array_key_last($plans)] : null;
            foreach ($plans as $plan) {
                if ($plan->getBlockedReason() !== null || $plan->getLatestExecution()?->getStatus() === ProductionOperationExecutionStatus::PAUSED) {
                    return null;
                }
            }
            $itemEnd = $last !== null ? ($ends[(int) $last->getId()] ?? null) : null;
            if ($itemEnd === null) {
                return null;
            }
            if ($finish === null || $itemEnd > $finish) {
                $finish = $itemEnd;
            }
        }

        return $finish;
    }

    private function latestActiveEnd(ServiceOrderOperationPlan $plan): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($plan->getReservations() as $reservation) {
            if (!$reservation->isActive()) {
                continue;
            }
            if ($latest === null || $reservation->getEndsAt() > $latest) {
                $latest = $reservation->getEndsAt();
            }
        }

        return $latest;
    }

    /** @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $segments */
    private function applySegments(ServiceOrderOperationPlan $plan, array $segments, User $actor): void
    {
        $activeRows = array_values(array_filter(
            $plan->getReservations()->toArray(),
            static fn (ProductionReservation $row): bool => $row->isActive(),
        ));
        usort($activeRows, static fn (ProductionReservation $left, ProductionReservation $right): int => $left->getSegmentNumber() <=> $right->getSegmentNumber());
        $maxSegment = 0;
        foreach ($plan->getReservations() as $row) {
            $maxSegment = max($maxSegment, $row->getSegmentNumber());
        }
        foreach ($segments as $index => $segment) {
            if (isset($activeRows[$index])) {
                $activeRows[$index]->reschedule($segment['start'], $segment['end']);
                continue;
            }
            $row = (new ProductionReservation())
                ->setPlan($plan)
                ->setEquipment($plan->getEquipment())
                ->setCreatedBy($actor)
                ->setSegmentNumber(++$maxSegment)
                ->setStartsAt($segment['start'])
                ->setEndsAt($segment['end']);
            $this->entityManager->persist($row);
            $plan->addReservation($row);
        }
        foreach (array_slice($activeRows, count($segments)) as $obsolete) {
            $obsolete->deactivate();
        }
    }

    /** @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $segments */
    private function segmentSnapshot(array $segments): array
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);

        return array_map(static fn (array $segment): array => [
            'start' => $segment['start']->setTimezone($timezone)->format(\DATE_ATOM),
            'end' => $segment['end']->setTimezone($timezone)->format(\DATE_ATOM),
        ], $segments);
    }

    /** @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $segments */
    private function assertCapacityCovers(Equipment $equipment, ServiceOrderOperationPlan $plan, array $segments): void
    {
        if (!$equipment->isSelectableForFutureExecution()) {
            throw new \DomainException('La máquina ya no está disponible para producción futura; reasigna el equipo antes de reprogramar.');
        }
        if ($plan->getEstimateKind() !== ProductionEstimateKind::CALCULATED) {
            return;
        }
        $estimate = $plan->getEstimateSnapshot() ?? [];
        if (($estimate['capacity_validated_at'] ?? null) === null) {
            throw new \DomainException('La estimación calculada no conserva una capacidad validada. Actualiza la estimación antes de reprogramar.');
        }
        foreach ($segments as $segment) {
            if (!$equipment->hasValidatedCapacityAt($segment['start']) || !$equipment->hasValidatedCapacityAt($segment['end']->modify('-1 second'))) {
                throw new \DomainException('La capacidad vigente de una máquina no cubre el nuevo horario; actualiza la estimación.');
            }
        }
    }

    private function lockQueueRows(\DateTimeImmutable $now): void
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) { return; }
        $utc = new \DateTimeZone('UTC');
        // These values are bound by DBAL directly (outside ORM type conversion).
        // Pass the database datetime representation explicitly instead of DateTimeImmutable.
        $start = $now->setTimezone($utc)->format('Y-m-d H:i:s');
        $end = $now->modify('+'.self::HORIZON_DAYS.' days')->setTimezone($utc)->format('Y-m-d H:i:s');
        $equipmentIds = $connection->fetchFirstColumn(
            'SELECT DISTINCT equipment_id FROM production_reservations WHERE is_active = 1 AND starts_at < ? AND ends_at > ? ORDER BY equipment_id',
            [$end, $start],
        );
        foreach ($equipmentIds as $id) {
            $connection->fetchOne('SELECT id FROM equipment WHERE id = ? FOR UPDATE', [(int) $id]);
        }
        $orderIds = $connection->fetchFirstColumn(
            'SELECT DISTINCT items.service_order_id FROM production_reservations reservations INNER JOIN service_order_operation_plans plans ON plans.id = reservations.plan_id INNER JOIN service_order_items items ON items.id = plans.service_order_item_id WHERE reservations.is_active = 1 AND reservations.starts_at < ? AND reservations.ends_at > ? ORDER BY items.service_order_id',
            [$end, $start],
        );
        foreach ($orderIds as $id) {
            $connection->fetchOne('SELECT id FROM service_orders WHERE id = ? FOR UPDATE', [(int) $id]);
        }
    }

    private function validateReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new \DomainException('Indica el motivo del cambio con entre 5 y 500 caracteres.');
        }

        return $reason;
    }

    private function ceilToNextMinute(\DateTimeImmutable $dateTime): \DateTimeImmutable
    {
        $minute = $dateTime->setTime((int) $dateTime->format('H'), (int) $dateTime->format('i'), 0, 0);
        if ($dateTime->format('s.u') !== '00.000000') {
            $minute = $minute->modify('+1 minute');
        }

        return $minute;
    }
}
