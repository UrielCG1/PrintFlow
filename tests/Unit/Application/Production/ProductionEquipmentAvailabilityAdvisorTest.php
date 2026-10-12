<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Production;

use App\Application\Production\ProductionEquipmentAvailabilityAdvisor;
use App\Application\Production\ProductionLoadResolver;
use App\Application\Production\ProductionScheduleCalculator;
use App\Application\Production\ProductionTimeEstimator;
use App\Entity\Equipment\Equipment;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Enum\Equipment\ProductionCapacityUnit;
use App\Enum\Orders\ProductionEstimateKind;
use PHPUnit\Framework\TestCase;

final class ProductionEquipmentAvailabilityAdvisorTest extends TestCase
{
    private const TZ = 'America/Mexico_City';

    public function testItRecommendsTheMachineThatFinishesFirstAfterEarlierOperationsAndExistingReservations(): void
    {
        $item = $this->createMock(ServiceOrderItem::class);
        $item->method('getId')->willReturn(10);

        $designMachine = $this->machine(1, 'EQ-PRE-MESA-01');
        $hpPlotter = $this->machine(2, 'EQ-IMP-HP-365');
        $skyPlotter = $this->machine(3, 'EQ-IMP-SKY-COLOR');
        $design = $this->plan(101, $item, null, 360, 'Diseño');
        $printing = $this->plan(102, $item, null, 1680, 'Impresión');

        $busyWindowsByEquipment = [
            2 => [
                ['start' => $this->local('2026-10-12 14:00'), 'end' => $this->local('2026-10-12 15:30')],
                ['start' => $this->local('2026-10-12 15:30'), 'end' => $this->local('2026-10-12 16:20')],
                ['start' => $this->local('2026-10-12 16:20'), 'end' => $this->local('2026-10-12 18:20')],
            ],
        ];

        $advisor = new ProductionEquipmentAvailabilityAdvisor(
            new ProductionScheduleCalculator(),
            new ProductionLoadResolver(),
            new ProductionTimeEstimator(),
        );
        $advice = $advisor->advise(
            [$design, $printing],
            [
                101 => [$designMachine],
                102 => [$hpPlotter, $skyPlotter],
            ],
            [],
            $busyWindowsByEquipment,
            [],
            $this->local('2026-10-11 18:40'),
        );

        self::assertSame('EQ-IMP-SKY-COLOR', $advice[102]['recommended_equipment']?->getCode());
        self::assertSame('earliest_completion', $advice[102]['recommendation_reason']);
        self::assertSame([
            ['EQ-IMP-SKY-COLOR', '2026-10-12 14:00', '2026-10-15 12:00'],
            ['EQ-IMP-HP-365', '2026-10-13 08:00', '2026-10-15 16:00'],
        ], array_map(static fn (array $option): array => [
            $option['equipment']->getCode(),
            $option['starts_at']?->format('Y-m-d H:i'),
            $option['ends_at']?->format('Y-m-d H:i'),
        ], $advice[102]['options']));
    }

    public function testItDoesNotClaimAWinnerWhenCompatibleMachinesFinishAtTheSameTime(): void
    {
        $item = $this->createMock(ServiceOrderItem::class);
        $item->method('getId')->willReturn(20);
        $printing = $this->plan(202, $item, null, 60, 'Impresión');
        $hpPlotter = $this->machine(4, 'EQ-IMP-HP-365');
        $skyPlotter = $this->machine(5, 'EQ-IMP-SKY-COLOR');
        $advisor = new ProductionEquipmentAvailabilityAdvisor(
            new ProductionScheduleCalculator(),
            new ProductionLoadResolver(),
            new ProductionTimeEstimator(),
        );

        $advice = $advisor->advise(
            [$printing],
            [202 => [$hpPlotter, $skyPlotter]],
            [],
            [],
            [],
            $this->local('2026-10-12 08:00'),
        );

        self::assertNull($advice[202]['recommended_equipment']);
        self::assertSame('same_completion', $advice[202]['recommendation_reason']);
        self::assertSame(
            $advice[202]['options'][0]['ends_at']?->format('Y-m-d H:i'),
            $advice[202]['options'][1]['ends_at']?->format('Y-m-d H:i'),
        );
    }

    public function testItUsesValidatedMachineRatesWhenProductDimensionsAllowPhysicalEstimates(): void
    {
        $item = $this->createMock(ServiceOrderItem::class);
        $item->method('getId')->willReturn(21);
        $item->method('getQuantity')->willReturn('100');
        $item->method('getProductionSpecificationsSnapshot')->willReturn([
            'finished_width_cm' => '100',
            'finished_height_cm' => '100',
        ]);
        $printing = $this->plan(212, $item, null, 1680, 'Impresión');
        $hpPlotter = $this->physicalMachine(41, 'EQ-IMP-HP-365', '23.0000');
        $skyPlotter = $this->physicalMachine(42, 'EQ-IMP-SKY-COLOR', '15.0000');
        $advisor = new ProductionEquipmentAvailabilityAdvisor(
            new ProductionScheduleCalculator(),
            new ProductionLoadResolver(),
            new ProductionTimeEstimator(),
        );

        $advice = $advisor->advise(
            [$printing],
            [212 => [$hpPlotter, $skyPlotter]],
            [],
            [],
            [],
            $this->local('2026-10-12 08:00'),
        );

        self::assertSame('EQ-IMP-HP-365', $advice[212]['recommended_equipment']?->getCode());
        self::assertSame([261, 400], array_column($advice[212]['options'], 'estimated_minutes'));
        self::assertSame('validated_capacity', $advice[212]['options'][0]['estimate_source']);
    }

    public function testItRecommendsTheOnlySelectableMachineWhenThePreviouslySelectedMachineIsUnavailable(): void
    {
        $item = $this->createMock(ServiceOrderItem::class);
        $item->method('getId')->willReturn(25);
        $unavailable = $this->machine(9, 'EQ-IMP-ANTERIOR', false);
        $available = $this->machine(10, 'EQ-IMP-SKY-COLOR');
        $printing = $this->plan(252, $item, $unavailable, 60, 'Impresión');
        $advisor = new ProductionEquipmentAvailabilityAdvisor(
            new ProductionScheduleCalculator(),
            new ProductionLoadResolver(),
            new ProductionTimeEstimator(),
        );

        $advice = $advisor->advise(
            [$printing],
            [252 => [$unavailable, $available]],
            [],
            [],
            [],
            $this->local('2026-10-12 08:00'),
        );

        self::assertSame('EQ-IMP-SKY-COLOR', $advice[252]['recommended_equipment']?->getCode());
        self::assertSame('only_compatible', $advice[252]['recommendation_reason']);
        self::assertCount(1, $advice[252]['options']);
    }

    public function testItKeepsTheUniqueMachineSuggestionWhenTheOperationStillNeedsAnEstimate(): void
    {
        $item = $this->createMock(ServiceOrderItem::class);
        $item->method('getId')->willReturn(26);
        $machine = $this->machine(11, 'EQ-IMP-HP-365');
        $printing = $this->plan(262, $item, null, null, 'Impresión', null);
        $advisor = new ProductionEquipmentAvailabilityAdvisor(
            new ProductionScheduleCalculator(),
            new ProductionLoadResolver(),
            new ProductionTimeEstimator(),
        );

        $advice = $advisor->advise(
            [$printing],
            [262 => [$machine]],
            [],
            [],
            [],
            $this->local('2026-10-12 08:00'),
        );

        self::assertSame('EQ-IMP-HP-365', $advice[262]['recommended_equipment']?->getCode());
        self::assertSame('only_compatible', $advice[262]['recommendation_reason']);
        self::assertNull($advice[262]['options'][0]['ends_at']);
    }

    public function testExistingPrinterBookingsBeforeThePreviousOperationFinishesDoNotArtificiallyDelayThatPrinter(): void
    {
        $item = $this->createMock(ServiceOrderItem::class);
        $item->method('getId')->willReturn(30);
        $designMachine = $this->machine(6, 'EQ-PRE-MESA-01');
        $hpPlotter = $this->machine(7, 'EQ-IMP-HP-365');
        $skyPlotter = $this->machine(8, 'EQ-IMP-SKY-COLOR');
        $design = $this->plan(301, $item, null, 360, 'Diseño');
        $printing = $this->plan(302, $item, null, 1680, 'Impresión');
        $advisor = new ProductionEquipmentAvailabilityAdvisor(
            new ProductionScheduleCalculator(),
            new ProductionLoadResolver(),
            new ProductionTimeEstimator(),
        );

        $advice = $advisor->advise(
            [$design, $printing],
            [301 => [$designMachine], 302 => [$hpPlotter, $skyPlotter]],
            [],
            [
                7 => [[
                    'start' => $this->local('2026-10-12 08:00'),
                    'end' => $this->local('2026-10-12 12:20'),
                ]],
            ],
            [],
            $this->local('2026-10-11 18:40'),
        );

        self::assertSame('same_completion', $advice[302]['recommendation_reason']);
        self::assertNull($advice[302]['recommended_equipment']);
        self::assertSame(
            ['2026-10-12 14:00', '2026-10-12 14:00'],
            array_map(static fn (array $option): string => $option['starts_at']?->format('Y-m-d H:i') ?? '', $advice[302]['options']),
        );
    }

    private function machine(int $id, string $code, bool $selectable = true): Equipment
    {
        $machine = $this->createMock(Equipment::class);
        $machine->method('getId')->willReturn($id);
        $machine->method('getCode')->willReturn($code);
        $machine->method('isSelectableForFutureExecution')->willReturn($selectable);

        return $machine;
    }

    private function physicalMachine(int $id, string $code, string $rate): Equipment
    {
        $machine = $this->machine($id, $code);
        $machine->method('getCapacityUnit')->willReturn(ProductionCapacityUnit::SQUARE_METERS_PER_HOUR);
        $machine->method('getCapacityRate')->willReturn($rate);
        $machine->method('getSetupMinutes')->willReturn(0);
        $machine->method('getCleanupMinutes')->willReturn(0);
        $machine->method('getEfficiencyPercent')->willReturn('100.00');
        $machine->method('hasValidatedCapacityAt')->willReturn(true);

        return $machine;
    }

    private function plan(
        int $id,
        ServiceOrderItem $item,
        ?Equipment $equipment,
        ?int $minutes,
        string $operationName,
        ?ProductionEstimateKind $estimateKind = ProductionEstimateKind::STANDARD,
    ): ServiceOrderOperationPlan {
        $plan = $this->createMock(ServiceOrderOperationPlan::class);
        $plan->method('getId')->willReturn($id);
        $plan->method('getServiceOrderItem')->willReturn($item);
        $plan->method('getEquipment')->willReturn($equipment);
        $plan->method('getEstimatedMinutes')->willReturn($minutes);
        $plan->method('getEstimateKind')->willReturn($estimateKind);
        $plan->method('getEstimateSnapshot')->willReturn(null);
        $plan->method('getOperationSnapshot')->willReturn(['name' => $operationName]);

        return $plan;
    }

    private function local(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone(self::TZ));
    }
}
