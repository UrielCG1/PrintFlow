<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Equipment\Equipment;
use App\Enum\Equipment\ProductionCapacityUnit;
use App\Enum\Equipment\ProductionLoadUnit;

final class ProductionTimeEstimator
{
    /** @return array{minutes: int, load: string, unit: ProductionLoadUnit, snapshot: array<string, mixed>} */
    public function estimate(
        Equipment $equipment,
        ProductionLoadUnit $unit,
        string $load,
        \DateTimeImmutable $scheduledAt,
    ): array {
        if (!$equipment->hasValidatedCapacityAt($scheduledAt)) {
            throw new \DomainException('El equipo no tiene capacidad numérica validada y vigente para esa fecha.');
        }

        $capacityUnit = $equipment->getCapacityUnit();
        if ($capacityUnit === null || $capacityUnit->loadUnit() !== $unit) {
            throw new \DomainException('La unidad de carga no coincide con la unidad de capacidad del equipo.');
        }

        $value = $this->positiveDecimal($load);
        $minutes = self::calculateMinutes(
            $capacityUnit,
            (float) $value,
            (float) $equipment->getCapacityRate(),
            $equipment->getSetupMinutes(),
            (float) $equipment->getEfficiencyPercent(),
            $equipment->getCleanupMinutes(),
        );

        return [
            'minutes' => $minutes,
            'load' => $value,
            'unit' => $unit,
            'snapshot' => [
                'version' => 'physical-estimate-v1',
                'capacity_unit' => $capacityUnit->value,
                'capacity_rate' => $equipment->getCapacityRate(),
                'setup_minutes' => $equipment->getSetupMinutes(),
                'cleanup_minutes' => $equipment->getCleanupMinutes(),
                'efficiency_percent' => $equipment->getEfficiencyPercent(),
                'capacity_valid_from' => $equipment->getCapacityValidFrom()?->format('Y-m-d'),
                'capacity_valid_until' => $equipment->getCapacityValidUntil()?->format('Y-m-d'),
                'capacity_validated_at' => $equipment->getCapacityValidatedAt()?->format(\DATE_ATOM),
                'usable_width_cm' => $equipment->getUsableWidthCm(),
                'equipment_id' => $equipment->getId(),
                'load_value' => $value,
                'load_unit' => $unit->value,
                'formula' => $capacityUnit === ProductionCapacityUnit::CUT_CENTIMETERS_PER_SECOND
                    ? 'setup + (path_cm / cm_per_second / efficiency) / 60 + cleanup'
                    : 'setup + (load / load_per_hour / efficiency) * 60 + cleanup',
            ],
        ];
    }

    public static function calculateMinutes(
        ProductionCapacityUnit $capacityUnit,
        float $load,
        float $rate,
        int $setupMinutes,
        float $efficiencyPercent,
        int $cleanupMinutes = 0,
    ): int {
        if ($load <= 0 || $rate <= 0 || $setupMinutes < 0 || $cleanupMinutes < 0 || $efficiencyPercent <= 0 || $efficiencyPercent > 100) {
            throw new \InvalidArgumentException('Carga, rendimiento, preparación o eficiencia fuera de rango.');
        }

        $efficiency = $efficiencyPercent / 100;
        $processingMinutes = match ($capacityUnit) {
            ProductionCapacityUnit::SQUARE_METERS_PER_HOUR,
            ProductionCapacityUnit::LINEAR_METERS_PER_HOUR => ($load / $rate) * 60 / $efficiency,
            ProductionCapacityUnit::CUT_CENTIMETERS_PER_SECOND => ($load / $rate) / 60 / $efficiency,
        };

        return max(1, (int) ceil($setupMinutes + $processingMinutes + $cleanupMinutes));
    }

    private function positiveDecimal(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (!is_numeric($value) || (float) $value <= 0 || (float) $value > 999999999) {
            throw new \InvalidArgumentException('La carga física debe ser un número positivo.');
        }

        return number_format((float) $value, 4, '.', '');
    }
}
