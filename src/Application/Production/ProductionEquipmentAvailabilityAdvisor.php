<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Equipment\Equipment;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionReservation;
use App\Enum\Orders\ProductionEstimateKind;

/** Builds a read-only schedule preview for compatible machines on a service order. */
final class ProductionEquipmentAvailabilityAdvisor
{
    private const TIMEZONE = 'America/Mexico_City';
    private const HORIZON_DAYS = 730;

    public function __construct(
        private readonly ProductionScheduleCalculator $scheduleCalculator,
        private readonly ProductionLoadResolver $loadResolver,
        private readonly ProductionTimeEstimator $timeEstimator,
    ) {
    }

    /**
     * @param list<ServiceOrderOperationPlan> $plans Ordered by item and operation sequence.
     * @param array<int, list<Equipment>> $equipmentChoicesByPlan
     * @param array<int, list<ProductionReservation>> $reservationsByPlan
     * @param array<int, list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>> $busyWindowsByEquipment
     * @param array<int, list<array{type: \App\Enum\Production\ProductionCalendarExceptionType, start: \DateTimeImmutable, end: \DateTimeImmutable}>> $exceptionsByEquipment
     * @return array<int, array{
     *     options: list<array{
     *         equipment: Equipment,
     *         starts_at: ?\DateTimeImmutable,
     *         ends_at: ?\DateTimeImmutable,
     *         estimated_minutes: ?int,
     *         estimate_source: ?string,
     *         unavailable_reason: ?string,
     *         recommended: bool
     *     }>,
     *     recommended_equipment: ?Equipment,
     *     recommendation_reason: ?string,
     *     blocked_by: ?string
     * }>
     */
    public function advise(
        array $plans,
        array $equipmentChoicesByPlan,
        array $reservationsByPlan,
        array $busyWindowsByEquipment,
        array $exceptionsByEquipment,
        \DateTimeImmutable $now,
    ): array {
        $now = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $projectedBusyByEquipment = [];
        $nextStartByItem = [];
        $blockedByItem = [];
        $adviceByPlan = [];

        foreach ($plans as $plan) {
            $planId = (int) $plan->getId();
            $itemId = (int) $plan->getServiceOrderItem()->getId();
            $activeReservations = $reservationsByPlan[$planId] ?? [];
            if ($activeReservations !== []) {
                $lastEnd = $this->lastReservationEnd($activeReservations);
                $nextStartByItem[$itemId] = $lastEnd;
                continue;
            }

            $blockedBy = $blockedByItem[$itemId] ?? null;
            $notBefore = $nextStartByItem[$itemId] ?? $now;
            if ($notBefore < $now) {
                $notBefore = $now;
            }
            $choices = $equipmentChoicesByPlan[$planId] ?? [];
            $selectedEquipment = $plan->getEquipment();
            if ($selectedEquipment !== null && !in_array($selectedEquipment, $choices, true)) {
                $choices[] = $selectedEquipment;
            }

            $options = [];
            foreach ($choices as $equipment) {
                if (!$equipment->isSelectableForFutureExecution()) {
                    continue;
                }

                $equipmentKey = $this->equipmentKey($equipment);
                $occupied = array_merge(
                    $busyWindowsByEquipment[$equipmentKey] ?? [],
                    $projectedBusyByEquipment[$equipmentKey] ?? [],
                );

                $option = $this->forecastOption(
                    $plan,
                    $equipment,
                    $blockedBy !== null ? $now : $notBefore,
                    $now,
                    $occupied,
                    $exceptionsByEquipment[$equipmentKey] ?? [],
                );
                if ($blockedBy !== null) {
                    // Machine availability is useful here, but this operation cannot start
                    // until its predecessor is planned and reserved.
                    $option['ends_at'] = null;
                    $option['unavailable_reason'] = sprintf('Primero debe completarse %s.', $blockedBy);
                }
                $options[] = $option;
            }

            usort($options, fn (array $left, array $right): int => $this->compareOptions($left, $right));
            $recommendedEquipment = null;
            $recommendationReason = null;
            if ($blockedBy === null && $options !== []) {
                // `choices` can contain the plan's previously selected machine even
                // when it has since become unavailable. Recommend the sole machine
                // that can still be planned, rather than leaving the operator stuck
                // with an obsolete selection plus one valid option.
                if (count($options) === 1) {
                    $onlyOption = $options[0];
                    $machineCanBeAssigned = $onlyOption['unavailable_reason'] === null
                        || ($onlyOption['starts_at'] !== null
                            && $onlyOption['ends_at'] === null
                            && $onlyOption['estimated_minutes'] === null);
                    if ($machineCanBeAssigned) {
                        $recommendedEquipment = $onlyOption['equipment'];
                        $recommendationReason = 'only_compatible';
                    }
                } elseif (isset($options[1])) {
                    $best = $options[0];
                    $next = $options[1];
                    if ($best['ends_at'] !== null
                        && $best['unavailable_reason'] === null
                        && ($next['ends_at'] === null
                            || $next['unavailable_reason'] !== null
                            || $best['ends_at'] < $next['ends_at'])
                    ) {
                        $recommendedEquipment = $best['equipment'];
                        $recommendationReason = 'earliest_completion';
                    } elseif ($best['ends_at'] === null
                        && $next['ends_at'] === null
                        && $best['starts_at'] !== null
                        && ($next['starts_at'] === null || $best['starts_at'] < $next['starts_at'])
                    ) {
                        $recommendedEquipment = $best['equipment'];
                        $recommendationReason = 'earliest_slot';
                    } else {
                        $recommendationReason = $best['ends_at'] !== null ? 'same_completion' : 'same_first_slot';
                    }
                }
                if ($recommendedEquipment !== null) {
                    foreach ($options as &$option) {
                        $option['recommended'] = $option['equipment'] === $recommendedEquipment;
                    }
                    unset($option);
                }
            }

            $adviceByPlan[$planId] = [
                'options' => $options,
                'recommended_equipment' => $recommendedEquipment,
                'recommendation_reason' => $recommendationReason,
                'blocked_by' => $blockedBy,
            ];

            if ($blockedBy !== null) {
                continue;
            }

            $projection = null;
            if ($selectedEquipment !== null) {
                foreach ($options as $option) {
                    if ($option['equipment'] === $selectedEquipment && $option['ends_at'] !== null && $option['unavailable_reason'] === null) {
                        $projection = $option;
                        break;
                    }
                }
            }
            $projection ??= $options[0] ?? null;
            if ($projection === null || $projection['ends_at'] === null || $projection['unavailable_reason'] !== null) {
                $blockedByItem[$itemId] = $this->operationLabel($plan);
                continue;
            }

            $nextStartByItem[$itemId] = $projection['ends_at'];
            $equipmentKey = $this->equipmentKey($projection['equipment']);
            foreach ($projection['_segments'] as $segment) {
                $projectedBusyByEquipment[$equipmentKey][] = $segment;
            }
        }

        // Segment details are used for downstream previews only, not exposed to Twig.
        foreach ($adviceByPlan as &$advice) {
            foreach ($advice['options'] as &$option) {
                unset($option['_segments']);
            }
            unset($option);
        }
        unset($advice);

        return $adviceByPlan;
    }

    /**
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $occupied
     * @param list<array{type: \App\Enum\Production\ProductionCalendarExceptionType, start: \DateTimeImmutable, end: \DateTimeImmutable}> $exceptions
     * @return array{
     *     equipment: Equipment,
     *     starts_at: ?\DateTimeImmutable,
     *     ends_at: ?\DateTimeImmutable,
     *     estimated_minutes: ?int,
     *     estimate_source: ?string,
     *     unavailable_reason: ?string,
     *     recommended: bool,
     *     _segments: list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>
     * }
     */
    private function forecastOption(
        ServiceOrderOperationPlan $plan,
        Equipment $equipment,
        \DateTimeImmutable $notBefore,
        \DateTimeImmutable $now,
        array $occupied,
        array $exceptions,
    ): array {
        try {
            $firstSlot = $this->scheduleCalculator->segments($notBefore, 1, $occupied, $exceptions, self::HORIZON_DAYS);
        } catch (\DomainException $exception) {
            return [
                'equipment' => $equipment,
                'starts_at' => null,
                'ends_at' => null,
                'estimated_minutes' => null,
                'estimate_source' => null,
                'unavailable_reason' => $exception->getMessage(),
                'recommended' => false,
                '_segments' => [],
            ];
        }
        $firstStart = $firstSlot[0]['start'];
        [$minutes, $source] = $this->estimateMinutes($plan, $equipment, $now);
        if ($minutes === null) {
            return [
                'equipment' => $equipment,
                'starts_at' => $firstStart,
                'ends_at' => null,
                'estimated_minutes' => null,
                'estimate_source' => null,
                'unavailable_reason' => 'Falta una estimación para calcular el término.',
                'recommended' => false,
                '_segments' => [],
            ];
        }

        try {
            $segments = $this->scheduleCalculator->segments($notBefore, $minutes, $occupied, $exceptions, self::HORIZON_DAYS);
        } catch (\DomainException $exception) {
            return [
                'equipment' => $equipment,
                'starts_at' => $firstStart,
                'ends_at' => null,
                'estimated_minutes' => $minutes,
                'estimate_source' => $source,
                'unavailable_reason' => $exception->getMessage(),
                'recommended' => false,
                '_segments' => [],
            ];
        }
        $unavailableReason = null;
        if (in_array($source, ['validated_capacity', 'calculated'], true)) {
            if ($source === 'calculated' && !$this->calculatedEstimateMatchesEquipment($plan, $equipment)) {
                $unavailableReason = 'La capacidad de la máquina cambió; actualiza la estimación antes de reservar.';
            }
            foreach ($segments as $segment) {
                if (!$equipment->hasValidatedCapacityAt($segment['start'])
                    || !$equipment->hasValidatedCapacityAt($segment['end']->modify('-1 second'))
                ) {
                    $unavailableReason ??= 'La capacidad validada no cubre todo el tiempo proyectado.';
                    break;
                }
            }
        }

        return [
            'equipment' => $equipment,
            'starts_at' => $segments[0]['start'],
            'ends_at' => $segments[array_key_last($segments)]['end'],
            'estimated_minutes' => $minutes,
            'estimate_source' => $source,
            'unavailable_reason' => $unavailableReason,
            'recommended' => false,
            '_segments' => $segments,
        ];
    }

    /** @return array{?int, ?string} */
    private function estimateMinutes(
        ServiceOrderOperationPlan $plan,
        Equipment $equipment,
        \DateTimeImmutable $now,
    ): array {
        $storedMinutes = $plan->getEstimatedMinutes();
        $kind = $plan->getEstimateKind();
        $snapshot = $plan->getEstimateSnapshot() ?? [];
        $unit = $equipment->getCapacityUnit()?->loadUnit();
        $load = $unit !== null ? $this->loadResolver->automaticLoad($plan->getServiceOrderItem(), $unit) : null;
        $sameCalculatedEquipment = $kind === ProductionEstimateKind::CALCULATED
            && (int) ($snapshot['equipment_id'] ?? 0) === (int) $equipment->getId();

        if ($kind === ProductionEstimateKind::MANUAL) {
            return [$storedMinutes, $storedMinutes !== null ? 'manual' : null];
        }
        if ($sameCalculatedEquipment) {
            return [$storedMinutes, $storedMinutes !== null ? 'calculated' : null];
        }

        $canEstimatePhysically = $unit !== null
            && $load !== null
            && $equipment->hasValidatedCapacityAt($now)
            && ($kind !== ProductionEstimateKind::CALCULATED || !$sameCalculatedEquipment);
        if ($canEstimatePhysically) {
            try {
                $estimate = $this->timeEstimator->estimate($equipment, $unit, $load, $now);

                return [$estimate['minutes'], 'validated_capacity'];
            } catch (\DomainException|\InvalidArgumentException) {
                // Fall back to the saved product standard when the machine rate cannot be used.
            }
        }

        if ($kind === ProductionEstimateKind::CALCULATED) {
            return [null, null];
        }

        if ($storedMinutes !== null) {
            return [$storedMinutes, match ($kind) {
                ProductionEstimateKind::STANDARD => 'product_standard',
                ProductionEstimateKind::MANUAL => 'manual',
                ProductionEstimateKind::CALCULATED => 'calculated',
                default => 'product_standard',
            }];
        }

        return [null, null];
    }

    private function calculatedEstimateMatchesEquipment(ServiceOrderOperationPlan $plan, Equipment $equipment): bool
    {
        $snapshot = $plan->getEstimateSnapshot() ?? [];
        return (int) ($snapshot['equipment_id'] ?? 0) === (int) $equipment->getId()
            && ($snapshot['capacity_unit'] ?? null) === $equipment->getCapacityUnit()?->value
            && ($snapshot['capacity_rate'] ?? null) === $equipment->getCapacityRate()
            && ($snapshot['setup_minutes'] ?? null) === $equipment->getSetupMinutes()
            && ($snapshot['cleanup_minutes'] ?? null) === $equipment->getCleanupMinutes()
            && ($snapshot['efficiency_percent'] ?? null) === $equipment->getEfficiencyPercent()
            && ($snapshot['usable_width_cm'] ?? null) === $equipment->getUsableWidthCm();
    }

    /** @param list<ProductionReservation> $reservations */
    private function lastReservationEnd(array $reservations): \DateTimeImmutable
    {
        $latest = null;
        foreach ($reservations as $reservation) {
            if ($latest === null || $reservation->getEndsAt() > $latest) {
                $latest = $reservation->getEndsAt();
            }
        }

        return $latest->setTimezone(new \DateTimeZone(self::TIMEZONE));
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareOptions(array $left, array $right): int
    {
        $leftEnd = $left['unavailable_reason'] === null ? $left['ends_at']?->getTimestamp() : null;
        $rightEnd = $right['unavailable_reason'] === null ? $right['ends_at']?->getTimestamp() : null;
        if ($leftEnd !== null && $rightEnd !== null && $leftEnd !== $rightEnd) {
            return $leftEnd <=> $rightEnd;
        }
        if ($leftEnd !== null && $rightEnd === null) {
            return -1;
        }
        if ($leftEnd === null && $rightEnd !== null) {
            return 1;
        }

        $leftStart = $left['starts_at']?->getTimestamp();
        $rightStart = $right['starts_at']?->getTimestamp();
        if ($leftStart !== null && $rightStart !== null && $leftStart !== $rightStart) {
            return $leftStart <=> $rightStart;
        }

        return strcasecmp($left['equipment']->getCode(), $right['equipment']->getCode());
    }

    private function equipmentKey(Equipment $equipment): int
    {
        return $equipment->getId() ?? spl_object_id($equipment);
    }

    private function operationLabel(ServiceOrderOperationPlan $plan): string
    {
        return (string) ($plan->getOperationSnapshot()['name'] ?? $plan->getOperation()->getName());
    }
}
