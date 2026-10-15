<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Production\ProductionReservation;
use App\Entity\Users\User;
use App\Enum\Equipment\ProductionLoadUnit;
use App\Enum\Orders\ProductionEstimateKind;
use App\Enum\Production\ProductionCalendarExceptionType;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Production\ProductionCalendarExceptionRepository;
use App\Repository\Production\ProductionReservationRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class ProductionPlanManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceOrderOperationPlanRepository $planRepository,
        private readonly ProductionReservationRepository $reservationRepository,
        private readonly ProductionCalendarExceptionRepository $exceptionRepository,
        private readonly ProductionTimeEstimator $estimator,
        private readonly ProductionLoadResolver $loadResolver,
        private readonly ProductionScheduleCalculator $scheduleCalculator,
        private readonly ProductionScheduleLock $scheduleLock,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function estimate(
        ServiceOrderOperationPlan $plan,
        ?string $load,
        ?ProductionLoadUnit $loadUnit,
        ?string $manualMinutes,
        string $reason,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($plan, $load, $loadUnit, $manualMinutes, $reason, $actor): void {
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->assertPendingPlanning($order);
            $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
            $this->assertPlanBelongsToOrder($order, $plan);
            $this->assertActivePlan($plan);
            if ($this->reservationRepository->findActiveForPlan($plan) !== []) {
                throw new \DomainException('No puedes cambiar la estimación después de reservar la máquina.');
            }

            $previous = $this->snapshot($plan);
            if (trim((string) $manualMinutes) !== '') {
                if (preg_match('/^[1-9]\d{0,5}$/D', trim((string) $manualMinutes)) !== 1) {
                    throw new \DomainException('Los minutos manuales deben ser un número entero positivo.');
                }
                if (trim((string) $load) !== '' && $loadUnit === null) {
                    throw new \DomainException('Indica la unidad de la carga física registrada.');
                }
                $availableData = [
                    'production_specifications' => $plan->getServiceOrderItem()->getProductionSpecificationsSnapshot(),
                    'equipment_id' => $plan->getEquipment()?->getId(),
                    'captured_load' => trim((string) $load) !== '' ? trim((string) $load) : null,
                    'load_unit' => $loadUnit?->value,
                ];
                $plan->setManualEstimate(
                    trim((string) $load) !== '' ? trim((string) $load) : null,
                    trim((string) $load) !== '' ? $loadUnit : null,
                    (int) $manualMinutes,
                    $reason,
                    $availableData,
                    $actor,
                );
            } else {
                $equipment = $plan->getEquipment();
                if ($equipment === null) {
                    throw new \DomainException('Asigna una máquina compatible antes de calcular el tiempo.');
                }
                $this->assertCompatibleMachine($plan, $equipment);
                $unit = $loadUnit ?? $equipment->getCapacityUnit()?->loadUnit();
                if ($unit === null) {
                    throw new \DomainException('Define la unidad física de carga; la ficha textual no se usa como capacidad.');
                }
                $loadValue = trim((string) $load);
                $source = 'captured_physical_load';
                if ($loadValue === '') {
                    $automaticLoad = $this->loadResolver->automaticLoad($plan->getServiceOrderItem(), $unit);
                    $loadValue = (string) ($automaticLoad ?? '');
                }
                if ($loadValue !== '' && trim((string) $load) === '') {
                    $source = 'dimensions_times_quantity';
                }
                if ($loadValue === '') {
                    throw new \DomainException(match ($unit) {
                        ProductionLoadUnit::CUT_CENTIMETERS => 'Captura los centímetros reales de trayectoria del vector. No se calcula corte usando solo piezas o área.',
                        ProductionLoadUnit::LINEAR_METERS => 'Captura los metros lineales reales según el acomodo y ancho del material.',
                        ProductionLoadUnit::SQUARE_METERS => 'Captura dimensiones de producción válidas para calcular el área física o indica la carga medida.',
                    });
                }
                $at = new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'));
                $estimate = $this->estimator->estimate($equipment, $unit, $loadValue, $at);
                $snapshot = $estimate['snapshot'] + ['load_source' => $source];
                $plan->setCalculatedEstimate($estimate['load'], $unit, $estimate['minutes'], $snapshot, $actor);
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'production.operation_estimated',
                entityType: 'service_order_operation_plan',
                entityId: $plan->getId(),
                oldValues: $previous,
                newValues: $this->snapshot($plan),
            );
            $this->entityManager->flush();
        });
    }

    /** @return list<ProductionReservation> */
    public function reserve(ServiceOrderOperationPlan $plan, User $actor): array
    {
        $scheduleLockAcquired = $this->scheduleLock->acquire();
        try {
            return $this->entityManager->wrapInTransaction(fn (): array => $this->reserveWithinTransaction($plan, $actor));
        } finally {
            $this->scheduleLock->release($scheduleLockAcquired);
        }
    }

    /** @return array{operations: int, segments: int} */
    public function reserveServiceOrder(ServiceOrder $serviceOrder, User $actor): array
    {
        $scheduleLockAcquired = $this->scheduleLock->acquire();
        try {
            return $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $actor): array {
                $this->entityManager->lock($serviceOrder, LockMode::PESSIMISTIC_WRITE);
                $this->assertPendingPlanning($serviceOrder);
                $plans = $this->planRepository->findActiveForServiceOrder($serviceOrder);
                if ($plans === []) {
                    throw new \DomainException('La orden no tiene operaciones activas para reservar.');
                }

                foreach ($serviceOrder->getItems() as $item) {
                    if ($item->getPlanningIssue() !== null) {
                        throw new \DomainException(sprintf('La partida %s requiere revisión antes de reservar: %s', $item->getDisplayLineNumber(), $item->getPlanningIssue()));
                    }
                }
                foreach ($plans as $plan) {
                    $this->assertActivePlan($plan);
                    if ($plan->getEquipment() === null) {
                        throw new \DomainException(sprintf('Falta seleccionar máquina para %s · partida %s.', $plan->getOperationSnapshot()['name'] ?? $plan->getOperation()->getName(), $plan->getServiceOrderItem()->getDisplayLineNumber()));
                    }
                    if ($plan->getEstimatedMinutes() === null || $plan->getEstimateKind() === null) {
                        throw new \DomainException(sprintf('Falta estimar %s · partida %s.', $plan->getOperationSnapshot()['name'] ?? $plan->getOperation()->getName(), $plan->getServiceOrderItem()->getDisplayLineNumber()));
                    }
                }

                $reservedOperations = 0;
                $segments = 0;
                foreach ($plans as $plan) {
                    if ($this->reservationRepository->findActiveForPlan($plan) !== []) {
                        continue;
                    }
                    $rows = $this->reserveWithinTransaction($plan, $actor);
                    ++$reservedOperations;
                    $segments += count($rows);
                }

                return ['operations' => $reservedOperations, 'segments' => $segments];
            });
        } finally {
            $this->scheduleLock->release($scheduleLockAcquired);
        }
    }

    /** @return list<ProductionReservation> */
    private function reserveWithinTransaction(ServiceOrderOperationPlan $plan, User $actor): array
    {
        $order = $plan->getServiceOrderItem()->getServiceOrder();
        $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
        $this->assertPendingPlanning($order);
        $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
        $this->assertPlanBelongsToOrder($order, $plan);
        $this->assertActivePlan($plan);

        if ($plan->getBlockedReason() !== null) {
            throw new \DomainException('La operación tiene un bloqueo y no puede reservarse todavía.');
        }
        if ($this->reservationRepository->findActiveForPlan($plan) !== []) {
            throw new \DomainException('Esta operación ya tiene una reserva activa.');
        }

        $equipment = $plan->getEquipment();
        if ($equipment === null) {
            throw new \DomainException('Asigna una máquina compatible antes de reservar.');
        }
        $id = $equipment->getId();
        if ($id === null) { throw new \LogicException('El equipo no tiene identificador.'); }
        $connection = $this->entityManager->getConnection();
        if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            $connection->fetchOne('SELECT id FROM equipment WHERE id = ? FOR UPDATE', [$id]);
        }
        $this->assertCompatibleMachine($plan, $equipment);
        $this->upgradeStandardEstimateToPhysical($plan, $actor);
        if ($plan->getEstimatedMinutes() === null || $plan->getEstimateKind() === null) {
            throw new \DomainException('Guarda primero una estimación estándar, calculada o manual.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'));
        $startAfter = $now;
        $previous = $this->planRepository->findPreviousActivePlan($plan);
        if ($previous !== null) {
            if ($previous->getBlockedReason() !== null) {
                throw new \DomainException('La operación anterior está bloqueada; resuélvela antes de reservar esta etapa.');
            }
            $previousReservations = $this->reservationRepository->findActiveForPlan($previous);
            if ($previousReservations === []) {
                throw new \DomainException('Reserva primero la operación anterior de esta partida.');
            }
            $last = $previousReservations[array_key_last($previousReservations)];
            $startAfter = $last->getEndsAt()->setTimezone(new \DateTimeZone('America/Mexico_City'));
        }

        $horizonEnd = $startAfter->modify('+730 days');
        $busy = array_map(static fn (ProductionReservation $reservation): array => [
            'start' => $reservation->getStartsAt(),
            'end' => $reservation->getEndsAt(),
        ], $this->reservationRepository->findActiveForEquipmentBetween($equipment, $startAfter, $horizonEnd));
        $exceptions = array_map(static fn ($exception): array => [
            'type' => $exception->getType(),
            'start' => $exception->getStartsAt(),
            'end' => $exception->getEndsAt(),
        ], $this->exceptionRepository->findActiveForEquipmentBetween($equipment, $startAfter, $horizonEnd));
        $segments = $this->scheduleCalculator->segments($startAfter, $plan->getEstimatedMinutes(), $busy, $exceptions);

        if ($plan->getEstimateKind() === ProductionEstimateKind::CALCULATED) {
            $capacity = $plan->getEstimateSnapshot() ?? [];
            if (
                ($capacity['equipment_id'] ?? null) !== $equipment->getId()
                || ($capacity['capacity_unit'] ?? null) !== $equipment->getCapacityUnit()?->value
                || ($capacity['capacity_rate'] ?? null) !== $equipment->getCapacityRate()
                || ($capacity['setup_minutes'] ?? null) !== $equipment->getSetupMinutes()
                || ($capacity['cleanup_minutes'] ?? null) !== $equipment->getCleanupMinutes()
                || ($capacity['efficiency_percent'] ?? null) !== $equipment->getEfficiencyPercent()
                || ($capacity['usable_width_cm'] ?? null) !== $equipment->getUsableWidthCm()
            ) {
                throw new \DomainException('La capacidad de la máquina cambió después del cálculo. Actualiza la estimación antes de reservar.');
            }
            foreach ($segments as $segment) {
                if (!$equipment->hasValidatedCapacityAt($segment['start']) || !$equipment->hasValidatedCapacityAt($segment['end']->modify('-1 second'))) {
                    throw new \DomainException('La capacidad del equipo no cubre toda la vigencia de la reserva; actualiza la estimación.');
                }
            }
        }

        $rows = [];
        foreach ($segments as $index => $segment) {
            $reservation = (new ProductionReservation())
                ->setPlan($plan)
                ->setEquipment($equipment)
                ->setCreatedBy($actor)
                ->setSegmentNumber($index + 1)
                ->setStartsAt($segment['start'])
                ->setEndsAt($segment['end']);
            $this->entityManager->persist($reservation);
            $plan->addReservation($reservation);
            $rows[] = $reservation;
        }

        $this->auditLogger->record(
            actor: $actor,
            action: 'production.machine_reserved',
            entityType: 'service_order_operation_plan',
            entityId: $plan->getId(),
            oldValues: null,
            newValues: [
                'equipment_id' => $equipment->getId(),
                'segments' => array_map(static fn (ProductionReservation $row): array => [
                    'start' => $row->getStartsAt()->setTimezone(new \DateTimeZone('America/Mexico_City'))->format(\DATE_ATOM),
                    'end' => $row->getEndsAt()->setTimezone(new \DateTimeZone('America/Mexico_City'))->format(\DATE_ATOM),
                ], $rows),
            ],
        );
        $this->entityManager->flush();

        return $rows;
    }

    public function assignOperator(ServiceOrderOperationPlan $plan, ?User $operator, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($plan, $operator, $actor): void {
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->assertOperatorAssignmentWindow($order);
            $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
            $this->assertPlanBelongsToOrder($order, $plan);
            $this->assignOperatorToUnstartedPlan($plan, $operator, $actor);
            $this->entityManager->flush();
        });
    }

    public function assignOperatorToPendingOrderOperations(ServiceOrder $order, User $operator, User $actor): int
    {
        return $this->entityManager->wrapInTransaction(function () use ($order, $operator, $actor): int {
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->assertOperatorAssignmentWindow($order);
            $this->assertValidOperator($operator);
            $changed = 0;

            foreach ($this->planRepository->findActiveForServiceOrder($order) as $plan) {
                $operationCode = $plan->getOperationSnapshot()['code'] ?? $plan->getOperation()->getCode();
                if ($operationCode === 'PRE-DISENO'
                    || !$plan->isActive()
                    || $plan->hasStartedExecution()
                    || $plan->getBlockedReason() !== null
                    || $plan->getAssignedOperator() !== null
                    || $this->reservationRepository->findActiveForPlan($plan) === []) {
                    continue;
                }
                $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
                if ($plan->getAssignedOperator() === $operator) {
                    continue;
                }
                $this->assignOperatorToUnstartedPlan($plan, $operator, $actor);
                ++$changed;
            }

            $this->entityManager->flush();

            return $changed;
        });
    }

    /** Replaces a route standard at reservation time when validated machine inputs exist. */
    private function upgradeStandardEstimateToPhysical(ServiceOrderOperationPlan $plan, User $actor): void
    {
        if ($plan->getEstimateKind() !== ProductionEstimateKind::STANDARD) {
            return;
        }
        $equipment = $plan->getEquipment();
        $unit = $equipment?->getCapacityUnit()?->loadUnit();
        if ($equipment === null || $unit === null) {
            return;
        }
        $load = $this->loadResolver->automaticLoad($plan->getServiceOrderItem(), $unit);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'));
        if ($load === null || !$equipment->hasValidatedCapacityAt($now)) {
            return;
        }

        $previous = $this->snapshot($plan);
        $estimate = $this->estimator->estimate($equipment, $unit, $load, $now);
        $plan->setCalculatedEstimate(
            $estimate['load'],
            $unit,
            $estimate['minutes'],
            $estimate['snapshot'] + ['load_source' => 'dimensions_times_quantity'],
            $actor,
        );
        $this->auditLogger->record(
            actor: $actor,
            action: 'production.operation_estimated',
            entityType: 'service_order_operation_plan',
            entityId: $plan->getId(),
            oldValues: $previous,
            newValues: $this->snapshot($plan),
        );
    }

    public function recordActualMinutes(ServiceOrderOperationPlan $plan, int $minutes, string $note, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($plan, $minutes, $note, $actor): void {
            $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
            if ($this->reservationRepository->findActiveForPlan($plan) === []) {
                throw new \DomainException('Registra el tiempo real después de que exista una reserva de máquina.');
            }
            $before = ['actual_minutes' => $plan->getActualMinutes(), 'actual_note' => $plan->getActualNote()];
            $plan->recordActualMinutes($minutes, $note, $actor);
            $this->auditLogger->record(
                actor: $actor,
                action: 'production.actual_time_recorded',
                entityType: 'service_order_operation_plan',
                entityId: $plan->getId(),
                oldValues: $before,
                newValues: ['actual_minutes' => $plan->getActualMinutes(), 'actual_note' => $plan->getActualNote()],
            );
            $this->entityManager->flush();
        });
    }

    private function assertCompatibleMachine(ServiceOrderOperationPlan $plan, \App\Entity\Equipment\Equipment $equipment): void
    {
        if (!$equipment->isSelectableForFutureExecution()) {
            throw new \DomainException('El equipo seleccionado ya no está disponible.');
        }
        if ($equipment->getPrimaryOperation()->getId() !== $plan->getOperation()->getId()) {
            throw new \DomainException('El equipo no es compatible con la operación de esta ruta.');
        }
        if (!$plan->getOperation()->isActive() || !$plan->getOperation()->getOperationArea()->isActive()) {
            throw new \DomainException('La operación o su área están inactivas.');
        }
    }

    private function assertPendingPlanning(\App\Entity\Orders\ServiceOrder $order): void
    {
        if (!$order->isPendingPlanning()) { throw new \DomainException('La orden ya no está en fase de planificación.'); }
    }

    private function assertPlanBelongsToOrder(\App\Entity\Orders\ServiceOrder $order, ServiceOrderOperationPlan $plan): void
    {
        if ($plan->getServiceOrderItem()->getServiceOrder()->getId() !== $order->getId()) {
            throw new \DomainException('La operación no pertenece a esta orden.');
        }
    }

    private function assertActivePlan(ServiceOrderOperationPlan $plan): void
    {
        if (!$plan->isActive()) { throw new \DomainException('La operación fue retirada de la ruta.'); }
        if ($plan->getBlockedReason() !== null) { throw new \DomainException('La operación está bloqueada y aún no puede planificarse.'); }
    }

    private function assertOperatorAssignmentWindow(ServiceOrder $order): void
    {
        if ((!$order->isPendingPlanning() && !$order->isPlanned())
            || in_array($order->getProductionStatus(), [
                \App\Enum\Orders\ProductionOrderStatus::WAITING_QUALITY,
                \App\Enum\Orders\ProductionOrderStatus::PRODUCTION_COMPLETED,
                \App\Enum\Orders\ProductionOrderStatus::CANCELLED,
            ], true)) {
            throw new \DomainException('La asignación solo está disponible antes de iniciar las operaciones de una orden pendiente o planificada.');
        }
    }

    private function assertValidOperator(User $operator): void
    {
        if (!$operator->isActive() || $operator->getDeletedAt() !== null || !in_array('ROLE_OPERATOR', $operator->getRoles(), true)) {
            throw new \DomainException('Selecciona un operador activo de Producción.');
        }
    }

    private function assignOperatorToUnstartedPlan(ServiceOrderOperationPlan $plan, ?User $operator, User $actor): void
    {
        $this->assertActivePlan($plan);
        $operationCode = $plan->getOperationSnapshot()['code'] ?? $plan->getOperation()->getCode();
        if ($operationCode === 'PRE-DISENO') {
            throw new \DomainException('Diseño se asigna por su perfil y no requiere un operador de Producción.');
        }
        if ($plan->hasStartedExecution()) {
            throw new \DomainException('No se puede cambiar la asignación después de iniciar la operación.');
        }
        if ($this->reservationRepository->findActiveForPlan($plan) === []) {
            throw new \DomainException('Asigna un operador después de reservar la máquina.');
        }
        if ($operator !== null) {
            $this->assertValidOperator($operator);
        }

        $before = $plan->getAssignedOperator();
        if ($before === $operator) {
            return;
        }
        $plan->assignOperator($operator);
        $this->auditLogger->record(
            actor: $actor,
            action: 'production.operation_operator_assigned',
            entityType: 'service_order_operation_plan',
            entityId: $plan->getId(),
            oldValues: ['operator_user_id' => $before?->getId()],
            newValues: ['operator_user_id' => $operator?->getId()],
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(ServiceOrderOperationPlan $plan): array
    {
        return [
            'equipment_id' => $plan->getEquipment()?->getId(),
            'estimate_kind' => $plan->getEstimateKind()?->value,
            'load_value' => $plan->getEstimatedLoadValue(),
            'load_unit' => $plan->getEstimatedLoadUnit()?->value,
            'estimated_minutes' => $plan->getEstimatedMinutes(),
            'manual_reason' => $plan->getManualEstimateReason(),
            'estimate_snapshot' => $plan->getEstimateSnapshot(),
        ];
    }
}
