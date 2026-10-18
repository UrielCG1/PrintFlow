<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Equipment\Equipment;
use App\Entity\Production\ProductionReservation;
use App\Enum\Production\ProductionOperationExecutionStatus;
use App\Repository\Equipment\EquipmentRepository;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Production\ProductionCalendarExceptionRepository;
use App\Repository\Production\ProductionReservationRepository;

/** An operational projection: no customer, quotation or price fields leave this boundary. */
final class ProductionBoardQuery
{
    public const ZONE = 'America/Mexico_City';

    public function __construct(
        private readonly EquipmentRepository $equipmentRepository,
        private readonly ProductionReservationRepository $reservationRepository,
        private readonly ServiceOrderOperationPlanRepository $planRepository,
        private readonly ProductionCalendarExceptionRepository $exceptionRepository,
        private readonly ProductionCommitmentForecaster $forecaster,
    ) {
    }

    /** @param array<string, string> $filters @return array<string, mixed> */
    public function build(\DateTimeImmutable $day, bool $week, array $filters = []): array
    {
        $zone = new \DateTimeZone(self::ZONE);
        $start = $week ? $day->modify('monday this week')->setTime(0, 0) : $day->setTime(0, 0);
        $end = $week ? $start->modify('+5 days') : $start->modify('+1 day');
        $equipment = $this->equipmentRepository->findBy([], ['name' => 'ASC']);
        $reservations = $this->reservationRepository->findActiveForProductionQueue($start, $end);
        $forecastByOrder = [];
        $rows = [];
        $allRows = [];
        $optionSets = ['area' => [], 'operation' => [], 'responsible' => []];

        foreach ($reservations as $reservation) {
            $plan = $reservation->getPlan();
            if (!$plan->isActive() || $plan->getConditionApplied() === false) {
                continue;
            }
            $item = $plan->getServiceOrderItem();
            $order = $item->getServiceOrder();
            if ($order->isCancelled()) { continue; }
            $machine = $reservation->getEquipment();
            $operation = $plan->getOperation();
            $area = $operation->getOperationArea();
            $operator = $plan->getAssignedOperator();
            $orderId = (int) $order->getId();
            $forecastByOrder[$orderId] ??= $this->forecaster->forOrder($order);
            $risk = $forecastByOrder[$orderId]['items'][(int) $item->getId()];
            $state = $this->state($reservation);
            $product = (string) ($item->getCommercialItemSnapshot()['name'] ?? $item->getCommercialItemSnapshot()['product_name'] ?? 'Producto');
            $next = $this->nextOperation($plan);

            $optionSets['area'][(int) $area->getId()] = $area->getName();
            $optionSets['operation'][(int) $operation->getId()] = $operation->getName();
            if ($operator !== null) {
                $optionSets['responsible'][(int) $operator->getId()] = $operator->getFullName();
            }

            $row = [
                'id' => (int) $reservation->getId(),
                'resource' => (string) $machine->getId(),
                'start' => $reservation->getStartsAt()->setTimezone($zone)->format('Y-m-d\TH:i:s'),
                'end' => $reservation->getEndsAt()->setTimezone($zone)->format('Y-m-d\TH:i:s'),
                'folio' => $order->getFolio(),
                'partida' => $item->getDisplayLineNumber(),
                'product' => $product,
                'operation' => $operation->getName(),
                'area' => $area->getName(),
                'equipment' => $machine->getName(),
                'responsible' => $operator?->getFullName() ?? 'Sin asignar',
                'priority' => $item->getPriority()->value,
                'priority_label' => $item->getPriority()->label(),
                'risk' => $risk['risk']->value,
                'risk_label' => $risk['risk_label'],
                'risk_cause' => $risk['risk_cause'],
                'status' => $state,
                'next' => $next,
                'commitment' => $order->getCommitmentDate()?->setTimezone($zone)->format('d/m/Y'),
                'plan_id' => (int) $plan->getId(),
                'order_id' => $orderId,
                'item_id' => (int) $item->getId(),
                'equipment_id' => (int) $machine->getId(),
                'area_id' => (int) $area->getId(),
                'operation_id' => (int) $operation->getId(),
                'responsible_id' => $operator?->getId(),
                'minutes' => (int) (($reservation->getEndsAt()->getTimestamp() - $reservation->getStartsAt()->getTimestamp()) / 60),
                'actual_minutes' => $plan->getActualMinutes() ?? $plan->getMeasuredActiveMinutes() ?? 0,
                'completed_at' => $plan->getLatestExecution()?->getCompletedAt()?->setTimezone($zone)->format('Y-m-d\TH:i:s'),
                'movable' => !$plan->hasStartedExecution() && !$plan->isSchedulePinned() && $reservation->getStartsAt() > new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ];
            $allRows[] = $row;
            if ($this->matches($row, $filters)) { $rows[] = $row; }
        }

        $priorityWeights = ['normal' => 0, 'high' => 1, 'urgent' => 2];
        usort($rows, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']) ?: (($priorityWeights[$b['priority']] ?? 0) <=> ($priorityWeights[$a['priority']] ?? 0)));
        $resources = [];
        $summary = ['reserved_minutes' => 0, 'executed_minutes' => 0, 'risk_count' => 0, 'blocked_count' => 0, 'in_progress' => 0];
        foreach ($equipment as $machine) {
            if (!$machine instanceof Equipment || $this->excludedMachine($machine, $filters)) {
                continue;
            }
            $resources[] = [
                'id' => (string) $machine->getId(),
                'name' => $machine->getName(),
                'area' => $machine->getPrimaryOperation()->getOperationArea()->getName(),
                'operation' => $machine->getPrimaryOperation()->getName(),
                'operation_id' => (int) $machine->getPrimaryOperation()->getId(),
                'reserved_minutes' => 0,
                'total_reserved_minutes' => 0,
                'executed_minutes' => 0,
                'free_minutes' => $this->capacityMinutes($machine, $start, $end),
                'alerts' => 0,
            ];
        }
        $resourceIndexes = array_flip(array_column($resources, 'id'));
        $counted = ['risk' => [], 'blocked' => [], 'in_progress' => [], 'completed' => []];
        foreach ($rows as $row) {
            if (!isset($resourceIndexes[$row['resource']])) {
                continue;
            }
            $index = $resourceIndexes[$row['resource']];
            $minutes = $this->visibleMinutes($row, $start, $end);
            $resources[$index]['reserved_minutes'] += $minutes;
            $summary['reserved_minutes'] += $minutes;
            if ($row['status'] === 'completed' && !isset($counted['completed'][$row['plan_id']])
                && $row['completed_at'] !== null && $row['completed_at'] >= $start->format('Y-m-d\TH:i:s')
                && $row['completed_at'] < $end->format('Y-m-d\TH:i:s')) {
                $resources[$index]['executed_minutes'] += $row['actual_minutes'];
                $summary['executed_minutes'] += $row['actual_minutes'];
                $counted['completed'][$row['plan_id']] = true;
            }
            if (in_array($row['risk'], ['late', 'tight'], true)) {
                if (!isset($counted['risk'][$row['plan_id']])) {
                    ++$resources[$index]['alerts'];
                    ++$summary['risk_count'];
                    $counted['risk'][$row['plan_id']] = true;
                }
            }
            if (in_array($row['status'], ['blocked', 'paused'], true) && !isset($counted['blocked'][$row['plan_id']])) {
                ++$summary['blocked_count'];
                $counted['blocked'][$row['plan_id']] = true;
            }
            if ($row['status'] === 'in_progress' && !isset($counted['in_progress'][$row['plan_id']])) {
                ++$summary['in_progress'];
                $counted['in_progress'][$row['plan_id']] = true;
            }
        }
        $occupiedByMachine = [];
        foreach ($allRows as $row) {
            $occupiedByMachine[$row['resource']] = ($occupiedByMachine[$row['resource']] ?? 0) + $this->visibleMinutes($row, $start, $end);
        }
        foreach ($resources as &$resource) {
            $resource['total_reserved_minutes'] = $occupiedByMachine[$resource['id']] ?? 0;
            $resource['free_minutes'] = max(0, $resource['free_minutes'] - $resource['total_reserved_minutes']);
        }
        unset($resource);

        $unreserved = $this->unreserved($filters, $forecastByOrder, $optionSets);

        return [
            'date' => $day->format('Y-m-d'),
            'range_start' => $start->format('Y-m-d'),
            'range_end' => $end->format('Y-m-d'),
            'view' => $week ? 'week' : 'day',
            'resources' => $resources,
            'events' => $rows,
            'summary' => $summary,
            'unreserved' => $unreserved,
            'options' => $optionSets,
        ];
    }

    /** @param array<string, string> $filters @param array<int, array<string, mixed>> $forecastByOrder @param array<string, array<int, string>> $optionSets @return list<array<string, mixed>> */
    private function unreserved(array $filters, array &$forecastByOrder, array &$optionSets): array
    {
        $rows = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($this->planRepository->findForProductionQueue() as $plan) {
            if (!$plan->isActive() || $plan->getConditionApplied() === false || $plan->hasCompletedLatestExecution()) {
                continue;
            }
            $hasCurrentReservation = false;
            foreach ($plan->getReservations() as $reservation) {
                if ($reservation->isActive() && $reservation->getEndsAt() > $now) { $hasCurrentReservation = true; break; }
            }
            if ($hasCurrentReservation) { continue; }
            $item = $plan->getServiceOrderItem();
            $order = $item->getServiceOrder();
            $operator = $plan->getAssignedOperator();
            $operation = $plan->getOperation();
            $equipment = $plan->getEquipment();
            $optionSets['area'][(int) $operation->getOperationArea()->getId()] = $operation->getOperationArea()->getName();
            $optionSets['operation'][(int) $operation->getId()] = $operation->getName();
            if ($operator !== null) { $optionSets['responsible'][(int) $operator->getId()] = $operator->getFullName(); }
            $orderId = (int) $order->getId();
            $forecastByOrder[$orderId] ??= $this->forecaster->forOrder($order);
            $risk = $forecastByOrder[$orderId]['items'][(int) $item->getId()];
            $latest = $plan->getLatestExecution()?->getStatus();
            $state = match (true) {
                $latest === ProductionOperationExecutionStatus::PAUSED => 'paused',
                $latest === ProductionOperationExecutionStatus::IN_PROGRESS => 'in_progress',
                $plan->getBlockedReason() !== null => 'blocked',
                default => 'pending',
            };
            $row = [
                'plan_id' => (int) $plan->getId(), 'folio' => $order->getFolio(),
                'partida' => $item->getDisplayLineNumber(),
                'product' => (string) ($item->getCommercialItemSnapshot()['name'] ?? 'Producto'),
                'operation' => $operation->getName(), 'area' => $operation->getOperationArea()->getName(),
                'equipment' => $equipment?->getName() ?? 'Sin máquina',
                'responsible' => $operator?->getFullName() ?? 'Sin asignar',
                'priority' => $item->getPriority()->value, 'priority_label' => $item->getPriority()->label(),
                'risk' => $risk['risk']->value, 'risk_label' => $risk['risk_label'],
                'status' => $state,
                'reason' => $plan->getBlockedReason() ?? match ($state) {
                    'paused' => 'Operación pausada; requiere seguimiento para recuperar la agenda.',
                    'in_progress' => 'Operación en curso sin una reserva vigente; revisa la agenda.',
                    default => 'Pendiente de reservar máquina y horario.',
                },
                'equipment_id' => $equipment?->getId(),
                'area_id' => $operation->getOperationArea()->getId(),
                'operation_id' => $operation->getId(),
                'responsible_id' => $operator?->getId(),
            ];
            if ($this->matches($row, $filters)) { $rows[] = $row; }
        }
        $weights = ['urgent' => 2, 'high' => 1, 'normal' => 0];
        usort($rows, static fn (array $a, array $b): int => ($weights[$b['priority']] <=> $weights[$a['priority']]) ?: strcmp($a['folio'], $b['folio']));
        return $rows;
    }

    private function state(ProductionReservation $reservation): string
    {
        $plan = $reservation->getPlan();
        $status = $plan->getLatestExecution()?->getStatus();
        return match (true) {
            $status === ProductionOperationExecutionStatus::COMPLETED => 'completed',
            $status === ProductionOperationExecutionStatus::IN_PROGRESS => 'in_progress',
            $status === ProductionOperationExecutionStatus::PAUSED => 'paused',
            $plan->getBlockedReason() !== null => 'blocked',
            default => 'reserved',
        };
    }

    private function nextOperation(\App\Entity\Orders\ServiceOrderOperationPlan $plan): string
    {
        $next = null;
        foreach ($plan->getServiceOrderItem()->getActiveOperationPlans() as $candidate) {
            if ($candidate->getSequenceNumber() > $plan->getSequenceNumber()
                && ($next === null || $candidate->getSequenceNumber() < $next->getSequenceNumber())) {
                $next = $candidate;
            }
        }
        return $next?->getOperation()->getName() ?? 'Calidad';
    }

    /** @param array<string, mixed> $row @param array<string, string> $filters */
    private function matches(array $row, array $filters): bool
    {
        foreach (['equipment', 'area', 'operation', 'responsible'] as $key) {
            if (($filters[$key] ?? '') !== '' && (string) $row[$key.'_id'] !== $filters[$key]) {
                return false;
            }
        }
        foreach (['status', 'priority', 'risk'] as $key) {
            if (($filters[$key] ?? '') !== '' && $row[$key] !== $filters[$key]) {
                return false;
            }
        }
        foreach (['order' => 'folio', 'product' => 'product'] as $filter => $field) {
            if (($filters[$filter] ?? '') !== '' && !str_contains(mb_strtolower($row[$field]), mb_strtolower($filters[$filter]))) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, string> $filters */
    private function excludedMachine(Equipment $machine, array $filters): bool
    {
        return (($filters['equipment'] ?? '') !== '' && (string) $machine->getId() !== $filters['equipment'])
            || (($filters['area'] ?? '') !== '' && (string) $machine->getPrimaryOperation()->getOperationArea()->getId() !== $filters['area'])
            || (($filters['operation'] ?? '') !== '' && (string) $machine->getPrimaryOperation()->getId() !== $filters['operation']);
    }

    /** @param array<string, mixed> $row */
    private function visibleMinutes(array $row, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $zone = new \DateTimeZone(self::ZONE);
        $from = max((new \DateTimeImmutable($row['start'], $zone))->getTimestamp(), $start->getTimestamp());
        $to = min((new \DateTimeImmutable($row['end'], $zone))->getTimestamp(), $end->getTimestamp());
        return max(0, (int) floor(($to - $from) / 60));
    }

    private function capacityMinutes(Equipment $machine, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $exceptions = $this->exceptionRepository->findActiveForEquipmentBetween($machine, $start, $end);
        $minutes = 0;
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $dayEnd = $day->modify('+1 day');
            $holiday = false;
            $blocked = [];
            $overtime = [];
            foreach ($exceptions as $exception) {
                $from = max($day->getTimestamp(), $exception->getStartsAt()->getTimestamp());
                $to = min($dayEnd->getTimestamp(), $exception->getEndsAt()->getTimestamp());
                if ($to <= $from) { continue; }
                switch ($exception->getType()->value) {
                    case 'holiday': $holiday = true; break;
                    case 'overtime': $overtime[] = [$from, $to]; break;
                    default: $blocked[] = [$from, $to];
                }
            }
            $windows = [];
            if (!$holiday && (int) $day->format('N') <= 5) {
                $windows[] = [$day->setTime(8, 0)->getTimestamp(), $day->setTime(18, 0)->getTimestamp()];
            }
            if (!$holiday) { array_push($windows, ...$overtime); }
            foreach ($this->mergeIntervals($windows) as [$from, $to]) {
                $available = $to - $from;
                foreach ($this->mergeIntervals($blocked) as [$blockFrom, $blockTo]) {
                    $available -= max(0, min($to, $blockTo) - max($from, $blockFrom));
                }
                $minutes += max(0, (int) floor($available / 60));
            }
        }
        return $minutes;
    }

    /** @param list<array{0: int, 1: int}> $intervals @return list<array{0: int, 1: int}> */
    private function mergeIntervals(array $intervals): array
    {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$from, $to]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $from <= $merged[$last][1]) { $merged[$last][1] = max($to, $merged[$last][1]); }
            else { $merged[] = [$from, $to]; }
        }
        return $merged;
    }
}
