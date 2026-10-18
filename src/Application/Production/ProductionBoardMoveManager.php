<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Production\ProductionReservation;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionEstimateKind;
use App\Repository\Equipment\EquipmentRepository;
use App\Repository\Production\ProductionCalendarExceptionRepository;
use App\Repository\Production\ProductionReservationRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** The calendar requests a move; this service alone decides whether it is valid. */
final class ProductionBoardMoveManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductionReservationRepository $reservations,
        private readonly ProductionCalendarExceptionRepository $exceptions,
        private readonly EquipmentRepository $equipment,
        private readonly ProductionScheduleCalculator $calculator,
        private readonly ProductionScheduleLock $scheduleLock,
        private readonly AuditLogger $auditLogger,
        private readonly PrintingManager $printingManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function preview(int $id, int $equipmentId, string $startInput, string $endInput): array
    {
        return $this->validate($id, $equipmentId, $startInput, $endInput);
    }

    /** @return array<string, mixed> */
    public function confirm(int $id, int $equipmentId, string $startInput, string $endInput, string $fingerprint, string $reason, User $actor): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new \DomainException('Indica un motivo de 5 a 500 caracteres para reprogramar.');
        }
        return $this->scheduleLock->synchronized(function () use ($id, $equipmentId, $startInput, $endInput, $fingerprint, $reason, $actor): array {
            return $this->entityManager->wrapInTransaction(function () use ($id, $equipmentId, $startInput, $endInput, $fingerprint, $reason, $actor): array {
                $reservation = $this->reservations->find($id);
                if (!$reservation instanceof ProductionReservation) {
                    throw new \DomainException('La reserva ya no existe.');
                }
                $plan = $reservation->getPlan();
                $this->entityManager->lock($plan->getServiceOrderItem()->getServiceOrder(), LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
                $newEquipment = $this->equipment->find($equipmentId);
                if ($newEquipment === null) {
                    throw new \DomainException('El equipo ya no existe.');
                }
                $this->entityManager->lock($newEquipment, LockMode::PESSIMISTIC_WRITE);
                $proposal = $this->validate($id, $equipmentId, $startInput, $endInput);
                if (!hash_equals($proposal['fingerprint'], $fingerprint)) {
                    throw new \DomainException('La agenda cambió desde la vista previa. Revísala antes de confirmar.');
                }
                $old = [
                    'equipment_id' => $reservation->getEquipment()->getId(),
                    'starts_at' => $reservation->getStartsAt()->format(\DATE_ATOM),
                    'ends_at' => $reservation->getEndsAt()->format(\DATE_ATOM),
                ];
                if ($reservation->getEquipment()->getId() !== $equipmentId) {
                    $snapshot = ['id' => $equipmentId, 'code' => $newEquipment->getCode(),
                        'name' => $newEquipment->getName(), 'operation_id' => $newEquipment->getPrimaryOperation()->getId()];
                    $plan->setEquipment($newEquipment, $snapshot);
                    $reservation->setEquipment($newEquipment);
                }
                $reservation->reschedule($proposal['start_utc'], $proposal['end_utc']);
                $this->auditLogger->record(
                    actor: $actor,
                    action: 'production.board_reservation_moved',
                    entityType: 'production_reservation',
                    entityId: $reservation->getId(),
                    oldValues: $old,
                    newValues: [
                        'equipment_id' => $equipmentId,
                        'starts_at' => $reservation->getStartsAt()->format(\DATE_ATOM),
                        'ends_at' => $reservation->getEndsAt()->format(\DATE_ATOM),
                        'reason' => $reason,
                    ],
                );
                $this->entityManager->flush();
                return ['id' => $id, 'message' => 'Reserva reprogramada.'];
            });
        });
    }

    /** @return array<string, mixed> */
    private function validate(int $id, int $equipmentId, string $startInput, string $endInput): array
    {
        $reservation = $this->reservations->find($id);
        $machine = $this->equipment->find($equipmentId);
        if (!$reservation instanceof ProductionReservation || !$reservation->isActive() || $machine === null) {
            throw new \DomainException('La reserva o la máquina ya no están disponibles.');
        }
        $plan = $reservation->getPlan();
        $order = $plan->getServiceOrderItem()->getServiceOrder();
        if (!$plan->isActive() || $plan->getConditionApplied() === false || $order->isCancelled()) {
            throw new \DomainException('La ruta o la orden ya no permiten reprogramar.');
        }
        if ($plan->hasStartedExecution() || $plan->isSchedulePinned() || $plan->getBlockedReason() !== null) {
            throw new \DomainException('Una operación iniciada, fijada o bloqueada no se puede mover.');
        }
        if (!$machine->isSelectableForFutureExecution() || $machine->getPrimaryOperation()->getId() !== $plan->getOperation()->getId()
            || !$plan->getOperation()->isActive() || !$plan->getOperation()->getOperationArea()->isActive()) {
            throw new \DomainException('La máquina no está disponible o no es compatible con la operación.');
        }
        $zone = new \DateTimeZone(ProductionBoardQuery::ZONE);
        $start = $this->localDate($startInput, $zone);
        $end = $this->localDate($endInput, $zone);
        $now = new \DateTimeImmutable('now', $zone);
        if ($reservation->getStartsAt() <= $now || $start <= $now || $end <= $start) {
            throw new \DomainException('Solo se pueden mover reservas futuras a un horario posterior al actual.');
        }
        $duration = $reservation->getEndsAt()->getTimestamp() - $reservation->getStartsAt()->getTimestamp();
        if ($end->getTimestamp() - $start->getTimestamp() !== $duration || $duration % 60 !== 0) {
            throw new \DomainException('El traslado debe conservar la duración de la reserva.');
        }
        if ($start->format('Y-m-d') !== $end->modify('-1 second')->format('Y-m-d')) {
            throw new \DomainException('Cada tramo debe permanecer dentro de una sola jornada.');
        }
        $sameMachine = $reservation->getEquipment()->getId() === $equipmentId;
        $segments = $this->reservations->findActiveForPlan($plan);
        if (!$sameMachine && $this->printingManager->isPrintingPlan($plan)) {
            throw new \DomainException('Cambia la máquina en Impresión para actualizar también la validación del archivo y material.');
        }
        if (!$sameMachine && (count($segments) !== 1 || $plan->getEstimateKind() === ProductionEstimateKind::CALCULATED)) {
            throw new \DomainException('Para cambiar de máquina, la reserva debe tener un solo tramo y una estimación independiente de la capacidad.');
        }
        foreach ($segments as $segment) {
            if ($segment->getId() === $id) {
                continue;
            }
            if (($segment->getSegmentNumber() < $reservation->getSegmentNumber() && $segment->getEndsAt() > $start)
                || ($segment->getSegmentNumber() > $reservation->getSegmentNumber() && $segment->getStartsAt() < $end)) {
                throw new \DomainException('El nuevo horario altera el orden de los tramos de esta operación.');
            }
        }
        foreach ($plan->getServiceOrderItem()->getActiveOperationPlans() as $otherPlan) {
            if ($otherPlan === $plan || $otherPlan->getConditionApplied() === false) {
                continue;
            }
            foreach ($this->reservations->findActiveForPlan($otherPlan) as $other) {
                if (($otherPlan->getSequenceNumber() < $plan->getSequenceNumber() && $other->getEndsAt() > $start)
                    || ($otherPlan->getSequenceNumber() > $plan->getSequenceNumber() && $other->getStartsAt() < $end)) {
                    throw new \DomainException('El nuevo horario rompe la secuencia de la ruta de producción.');
                }
            }
        }
        $busy = [];
        $version = [];
        foreach ($this->reservations->findActiveForEquipmentBetween($machine, $start, $end) as $other) {
            if ($other->getId() === $id) {
                continue;
            }
            $busy[] = ['start' => $other->getStartsAt(), 'end' => $other->getEndsAt()];
            $version[] = [$other->getId(), $other->getStartsAt()->getTimestamp(), $other->getEndsAt()->getTimestamp()];
        }
        $exceptions = [];
        foreach ($this->exceptions->findActiveForEquipmentBetween($machine, $start, $end) as $exception) {
            $exceptions[] = ['type' => $exception->getType(), 'start' => $exception->getStartsAt(), 'end' => $exception->getEndsAt()];
            $version[] = [$exception->getId(), $exception->getType()->value, $exception->getStartsAt()->getTimestamp(), $exception->getEndsAt()->getTimestamp()];
        }
        $available = $this->calculator->segments($start, (int) ($duration / 60), $busy, $exceptions, 3);
        if (count($available) !== 1 || $available[0]['start']->getTimestamp() !== $start->getTimestamp()
            || $available[0]['end']->getTimestamp() !== $end->getTimestamp()) {
            throw new \DomainException('El horario propuesto no cabe en la jornada o se cruza con una reserva o excepción.');
        }
        if ($plan->getEstimateKind() === ProductionEstimateKind::CALCULATED
            && (!$machine->hasValidatedCapacityAt($start) || !$machine->hasValidatedCapacityAt($end->modify('-1 second')))) {
            throw new \DomainException('La capacidad validada del equipo no cubre el nuevo horario.');
        }
        $fingerprint = hash('sha256', json_encode([
            $id, $equipmentId, $start->getTimestamp(), $end->getTimestamp(),
            $reservation->getStartsAt()->getTimestamp(), $reservation->getEndsAt()->getTimestamp(),
            $plan->getUpdatedAt()->getTimestamp(), $version,
        ], \JSON_THROW_ON_ERROR));
        return [
            'id' => $id,
            'equipment' => $machine->getName(),
            'start' => $start->format('d/m/Y H:i'),
            'end' => $end->format('d/m/Y H:i'),
            'start_utc' => $start->setTimezone(new \DateTimeZone('UTC')),
            'end_utc' => $end->setTimezone(new \DateTimeZone('UTC')),
            'fingerprint' => $fingerprint,
        ];
    }

    private function localDate(string $value, \DateTimeZone $zone): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, $zone);
        if ($date === false || $date->format('Y-m-d\TH:i:s') !== $value) {
            throw new \DomainException('La fecha y hora propuestas no son válidas.');
        }
        return $date;
    }
}
