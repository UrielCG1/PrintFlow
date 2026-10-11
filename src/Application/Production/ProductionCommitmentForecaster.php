<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionReservation;
use App\Repository\Production\ProductionCalendarExceptionRepository;
use App\Repository\Production\ProductionReservationRepository;
use App\Enum\Production\ProductionOperationExecutionStatus;

final class ProductionCommitmentForecaster
{
    private const TIMEZONE = 'America/Mexico_City';

    public function __construct(
        private readonly ProductionReservationRepository $reservationRepository,
        private readonly ProductionCalendarExceptionRepository $exceptionRepository,
        private readonly ProductionCommitmentAnalyzer $analyzer,
    ) {
    }

    /**
     * @return array{
     *     order: array<string, mixed>,
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function forOrder(ServiceOrder $order): array
    {
        $plansByItem = [];
        foreach ($order->getItems() as $item) {
            $plans = array_values(array_filter(
                $item->getActiveOperationPlans(),
                static fn (ServiceOrderOperationPlan $plan): bool => $plan->getConditionApplied() !== false,
            ));
            usort($plans, static fn (ServiceOrderOperationPlan $left, ServiceOrderOperationPlan $right): int => $left->getSequenceNumber() <=> $right->getSequenceNumber());
            $plansByItem[(int) $item->getId()] = $plans;
        }

        $reservationsByPlan = [];
        foreach ($this->reservationRepository->findActiveForServiceOrder($order) as $reservation) {
            $reservationsByPlan[(int) $reservation->getPlan()->getId()][] = $reservation;
        }

        $finishes = [];
        $itemsWithFinish = [];
        $causes = [];
        $allItemsScheduled = true;
        foreach ($order->getItems() as $item) {
            $plans = $plansByItem[(int) $item->getId()] ?? [];
            $lastPlan = $plans !== [] ? $plans[array_key_last($plans)] : null;
            $finish = $lastPlan?->hasCompletedLatestExecution()
                ? $lastPlan->getLatestExecution()->getCompletedAt()
                : ($lastPlan !== null ? $this->latestReservationEnd($reservationsByPlan[(int) $lastPlan->getId()] ?? []) : null);
            foreach ($plans as $plan) {
                if ($plan->getBlockedReason() !== null || $plan->getLatestExecution()?->getStatus() === ProductionOperationExecutionStatus::PAUSED) {
                    $finish = null;
                    $causes[(int) $item->getId()] = 'La ruta está pausada o bloqueada. El pronóstico se recuperará al reanudar o resolver la incidencia.';
                    break;
                }
                if (!$plan->hasCompletedLatestExecution() && ($reservationsByPlan[(int) $plan->getId()] ?? []) === []) {
                    $finish = null;
                    break;
                }
            }
            $itemsWithFinish[(int) $item->getId()] = $finish;
            if ($finish === null) {
                $allItemsScheduled = false;
            } else {
                $finishes[] = $finish;
            }
        }

        $orderFinish = $allItemsScheduled && $finishes !== [] ? max($finishes) : null;
        $dateRange = $this->holidayRange($finishes, $order->getCommitmentDate());
        $holidays = $dateRange === null ? [] : array_map(
            static fn ($exception): array => ['start' => $exception->getStartsAt(), 'end' => $exception->getEndsAt()],
            $this->exceptionRepository->findGlobalHolidaysBetween($dateRange[0], $dateRange[1]),
        );

        $items = [];
        foreach ($order->getItems() as $item) {
            $items[(int) $item->getId()] = $this->analyzer->analyze(
                $itemsWithFinish[(int) $item->getId()] ?? null,
                $order->getCommitmentDate(),
                $holidays,
            );
            if (isset($causes[(int) $item->getId()])) {
                $items[(int) $item->getId()]['risk_cause'] = $causes[(int) $item->getId()];
            }
        }

        $orderForecast = $this->analyzer->analyze($orderFinish, $order->getCommitmentDate(), $holidays);
        if ($causes !== []) { $orderForecast['risk_cause'] = reset($causes); }

        return [
            'order' => $orderForecast,
            'items' => $items,
        ];
    }

    /** @param list<ProductionReservation> $reservations */
    private function latestReservationEnd(array $reservations): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($reservations as $reservation) {
            if (!$reservation->isActive()) {
                continue;
            }
            if ($latest === null || $reservation->getEndsAt() > $latest) {
                $latest = $reservation->getEndsAt();
            }
        }

        return $latest;
    }

    /** @param list<\DateTimeImmutable> $finishes @return array{\DateTimeImmutable, \DateTimeImmutable}|null */
    private function holidayRange(array $finishes, ?\DateTimeImmutable $commitment): ?array
    {
        if ($finishes === [] && $commitment === null) {
            return null;
        }
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $dates = array_map(static fn (\DateTimeImmutable $date): \DateTimeImmutable => $date->setTimezone($timezone), $finishes);
        if ($commitment !== null) {
            $dates[] = $commitment->setTimezone($timezone);
        }
        $start = min($dates)->setTime(0, 0);
        $end = max($dates)->modify('+5 days')->setTime(23, 59, 59);

        return [$start, $end];
    }
}
