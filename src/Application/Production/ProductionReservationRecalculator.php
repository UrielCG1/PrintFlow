<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Equipment\Equipment;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionReservation;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionEstimateKind;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Production\ProductionCalendarExceptionRepository;
use App\Repository\Production\ProductionReservationRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class ProductionReservationRecalculator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductionReservationRepository $reservationRepository,
        private readonly ProductionCalendarExceptionRepository $exceptionRepository,
        private readonly ServiceOrderOperationPlanRepository $planRepository,
        private readonly ProductionScheduleCalculator $scheduleCalculator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function recalculate(?Equipment $equipment, \DateTimeImmutable $changedStart, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($equipment, $changedStart, $actor): void {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'));
            $horizon = $now->modify('+730 days');
            $machines = $equipment === null
                ? $this->reservationRepository->findEquipmentWithReservationsAfter($now, $horizon)
                : [$equipment];

            // A second pass propagates a shift to a later operation on another machine.
            for ($pass = 0; $pass <= count($machines); ++$pass) {
                $changed = false;
                foreach ($machines as $machine) {
                    $changed = $this->recalculateMachine($machine, $changedStart, $now, $horizon, $actor) || $changed;
                }
                if (!$changed) { break; }
            }
        });
    }

    private function recalculateMachine(
        Equipment $equipment,
        \DateTimeImmutable $changedStart,
        \DateTimeImmutable $now,
        \DateTimeImmutable $horizon,
        User $actor,
    ): bool {
        $id = $equipment->getId();
        if ($id === null) { return false; }
        $this->entityManager->getConnection()->fetchOne('SELECT id FROM equipment WHERE id = ? FOR UPDATE', [$id]);
        $reservations = $this->reservationRepository->findActiveForEquipmentBetween($equipment, $now, $horizon);
        $candidatePlans = [];
        foreach ($reservations as $reservation) {
            if ($reservation->getStartsAt() > $now && $reservation->getEndsAt() > $changedStart) {
                $candidatePlans[(int) $reservation->getPlan()->getId()] = $reservation->getPlan();
            }
        }

        $candidates = [];
        foreach ($candidatePlans as $plan) {
            $activeRows = array_values(array_filter(
                $plan->getReservations()->toArray(),
                static fn (ProductionReservation $row): bool => $row->isActive(),
            ));
            usort($activeRows, static fn (ProductionReservation $left, ProductionReservation $right): int => $left->getSegmentNumber() <=> $right->getSegmentNumber());
            $affectedRows = array_values(array_filter(
                $activeRows,
                static fn (ProductionReservation $row): bool => $row->getStartsAt() > $now && $row->getEndsAt() > $changedStart,
            ));
            if ($affectedRows === [] || $plan->getEstimatedMinutes() === null) { continue; }
            $candidates[] = [
                'plan' => $plan,
                'rows' => $activeRows,
                'affected_rows' => $affectedRows,
                'first_start' => $affectedRows[0]->getStartsAt(),
            ];
        }
        usort($candidates, static fn (array $left, array $right): int => $left['first_start'] <=> $right['first_start']);
        if ($candidates === []) { return false; }

        $affectedReservationIds = [];
        foreach ($candidates as $candidate) {
            foreach ($candidate['affected_rows'] as $row) {
                $affectedReservationIds[spl_object_id($row)] = true;
            }
        }
        $busy = [];
        foreach ($reservations as $reservation) {
            if (!isset($affectedReservationIds[spl_object_id($reservation)])) {
                $busy[] = ['start' => $reservation->getStartsAt(), 'end' => $reservation->getEndsAt()];
            }
        }

        $changed = false;
        foreach ($candidates as $candidate) {
            /** @var ServiceOrderOperationPlan $plan */
            $plan = $candidate['plan'];
            /** @var list<ProductionReservation> $oldRows */
            $oldRows = $candidate['rows'];
            /** @var list<ProductionReservation> $affectedRows */
            $affectedRows = $candidate['affected_rows'];
            $affectedIds = array_fill_keys(array_map('spl_object_id', $affectedRows), true);
            $fixedRows = array_values(array_filter(
                $oldRows,
                static fn (ProductionReservation $row): bool => !isset($affectedIds[spl_object_id($row)]),
            ));
            $notBefore = $candidate['first_start'] > $now ? $candidate['first_start'] : $now;
            $previous = $this->planRepository->findPreviousActivePlan($plan);
            if ($previous !== null) {
                $previousEnd = $this->latestActiveReservationEnd($previous);
                if ($previousEnd === null) {
                    throw new \DomainException('Falta la reserva de la operación anterior; no se pueden recalcular las siguientes.');
                }
                if ($previousEnd > $notBefore) { $notBefore = $previousEnd; }
            }

            $exceptionRows = $this->exceptionRepository->findActiveForEquipmentBetween($equipment, $notBefore, $horizon);
            $exceptions = array_map(static fn ($exception): array => [
                'type' => $exception->getType(),
                'start' => $exception->getStartsAt(),
                'end' => $exception->getEndsAt(),
            ], $exceptionRows);
            $remainingSeconds = array_sum(array_map(
                static fn (ProductionReservation $row): int => $row->getEndsAt()->getTimestamp() - $row->getStartsAt()->getTimestamp(),
                $affectedRows,
            ));
            if ($remainingSeconds < 60 || $remainingSeconds % 60 !== 0) {
                throw new \DomainException('La duración restante de una reserva debe expresarse en minutos completos.');
            }
            $segments = $this->scheduleCalculator->segments($notBefore, (int) ($remainingSeconds / 60), $busy, $exceptions);
            $this->assertCapacityCovers($equipment, $plan, $segments);

            $before = array_map(static fn (ProductionReservation $row): array => [
                'start' => $row->getStartsAt()->format(\DATE_ATOM),
                'end' => $row->getEndsAt()->format(\DATE_ATOM),
            ], $oldRows);
            $maxSegment = 0;
            foreach ($plan->getReservations() as $row) { $maxSegment = max($maxSegment, $row->getSegmentNumber()); }
            $newAffectedRows = [];
            foreach ($segments as $index => $segment) {
                if (isset($affectedRows[$index])) {
                    $row = $affectedRows[$index]->reschedule($segment['start'], $segment['end']);
                } else {
                    $row = (new ProductionReservation())
                        ->setPlan($plan)
                        ->setEquipment($equipment)
                        ->setCreatedBy($actor)
                        ->setSegmentNumber(++$maxSegment)
                        ->setStartsAt($segment['start'])
                        ->setEndsAt($segment['end']);
                    $this->entityManager->persist($row);
                    $plan->addReservation($row);
                }
                $newAffectedRows[] = $row;
            }
            foreach (array_slice($affectedRows, count($segments)) as $obsolete) { $obsolete->deactivate(); }
            $newRows = array_merge($fixedRows, $newAffectedRows);
            usort($newRows, static fn (ProductionReservation $left, ProductionReservation $right): int => $left->getSegmentNumber() <=> $right->getSegmentNumber());
            $after = array_map(static fn (ProductionReservation $row): array => [
                'start' => $row->getStartsAt()->format(\DATE_ATOM),
                'end' => $row->getEndsAt()->format(\DATE_ATOM),
            ], $newRows);
            if ($before !== $after) {
                $changed = true;
                $this->auditLogger->record(
                    actor: $actor,
                    action: 'production.future_reservations_recalculated',
                    entityType: 'service_order_operation_plan',
                    entityId: $plan->getId(),
                    oldValues: ['segments' => $before],
                    newValues: ['equipment_id' => $equipment->getId(), 'segments' => $after],
                );
            }
            foreach ($newRows as $row) { $busy[] = ['start' => $row->getStartsAt(), 'end' => $row->getEndsAt()]; }
        }

        $this->entityManager->flush();
        return $changed;
    }

    private function assertCapacityCovers(Equipment $equipment, ServiceOrderOperationPlan $plan, array $segments): void
    {
        if ($plan->getEstimateKind() !== ProductionEstimateKind::CALCULATED) { return; }
        $estimate = $plan->getEstimateSnapshot() ?? [];
        if (($estimate['capacity_validated_at'] ?? null) === null) {
            throw new \DomainException('La estimación calculada no conserva una capacidad validada.');
        }
        foreach ($segments as $segment) {
            if (!$equipment->hasValidatedCapacityAt($segment['start']) || !$equipment->hasValidatedCapacityAt($segment['end']->modify('-1 second'))) {
                throw new \DomainException('La capacidad vigente no cubre la nueva reserva; actualiza la estimación.');
            }
        }
    }

    private function latestActiveReservationEnd(ServiceOrderOperationPlan $plan): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($plan->getReservations() as $reservation) {
            if (!$reservation->isActive()) { continue; }
            if ($latest === null || $reservation->getEndsAt() > $latest) { $latest = $reservation->getEndsAt(); }
        }
        return $latest;
    }
}
