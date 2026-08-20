<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Users\User;
use App\Repository\Catalog\CommercialItemRepository;
use App\Repository\Catalog\MeasurementUnitRepository;
use App\Repository\Materials\MaterialRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class MeasurementUnitManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly CommercialItemRepository $commercialItemRepository,
        private readonly MaterialRepository $materialRepository,
        private readonly MeasurementUnitRepository $measurementUnitRepository,
    ) {
    }

    public function create(MeasurementUnitData $data, User $actor): MeasurementUnit
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): MeasurementUnit {
            $this->validateTechnicalConfiguration(null, $data);

            if ($data->displayOrder <= 0 && $data->dimensionType !== null) {
                $data->displayOrder = $this->measurementUnitRepository->nextDisplayOrderForDimension(
                    $data->dimensionType->value,
                );
            }

            $unit = new MeasurementUnit();
            $this->applyData($unit, $data);

            $this->entityManager->persist($unit);
            $this->entityManager->flush();

            $this->auditLogger->record(
                $actor,
                'measurement_unit.created',
                'measurement_unit',
                $unit->getId(),
                null,
                $this->snapshot($unit),
            );
            $this->entityManager->flush();

            return $unit;
        });
    }

    public function update(MeasurementUnit $unit, MeasurementUnitData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($unit, $data, $actor): void {
            $this->validateTechnicalConfiguration($unit, $data);

            $oldValues = $this->snapshot($unit);

            if (
                $data->dimensionType !== null
                && $unit->getDimensionType() !== $data->dimensionType->value
            ) {
                $data->displayOrder = $this->measurementUnitRepository->nextDisplayOrderForDimension(
                    $data->dimensionType->value,
                );
            }

            $this->applyData($unit, $data);
            $newValues = $this->snapshot($unit);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record(
                $actor,
                'measurement_unit.updated',
                'measurement_unit',
                $unit->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function setActive(MeasurementUnit $unit, bool $isActive, User $actor): void
    {
        if ($unit->isActive() === $isActive) {
            return;
        }

        if ($isActive && $unit->getBaseUnit() !== null && !$unit->getBaseUnit()->isActive()) {
            throw new \DomainException('No puedes reactivar esta unidad mientras su unidad base permanezca inactiva.');
        }

        if (!$isActive && $this->commercialItemRepository->hasActiveForMeasurementUnit($unit)) {
            throw new \DomainException('No puedes desactivar una unidad de medida que está siendo utilizada por productos o servicios activos.');
        }

        if (!$isActive && $this->materialRepository->hasActiveForMeasurementUnit($unit)) {
            throw new \DomainException('No puedes desactivar una unidad de medida que tiene materiales operativos activos.');
        }

        if (!$isActive && $this->measurementUnitRepository->hasActiveDerivedUnits($unit)) {
            throw new \DomainException('No puedes desactivar una unidad base mientras existan unidades activas que dependan de ella.');
        }

        $this->entityManager->wrapInTransaction(function () use ($unit, $isActive, $actor): void {
            $oldValues = $this->snapshot($unit);
            $unit->setIsActive($isActive);
            $newValues = $this->snapshot($unit);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'measurement_unit.activated' : 'measurement_unit.deactivated',
                'measurement_unit',
                $unit->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    /**
     * Reordena únicamente las unidades activas de la misma dimensión que la
     * unidad movida. Así el orden deja de mezclar conteo, área, tiempo, etc.
     */
    public function reorderActive(
        int $movedId,
        ?int $beforeId,
        ?int $afterId,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($movedId, $beforeId, $afterId, $actor): void {
            $movedReference = $this->measurementUnitRepository->find($movedId);

            if (!$movedReference instanceof MeasurementUnit || !$movedReference->isActive()) {
                throw new \DomainException('La unidad que intentas reordenar ya no está disponible.');
            }

            $units = $this->measurementUnitRepository->findActiveDimensionOrderedForUpdate(
                $movedReference->getDimensionType(),
            );
            $movedUnit = $this->findActiveUnit($units, $movedId);

            if ($movedUnit === null) {
                throw new \DomainException('La unidad que intentas reordenar ya no está disponible.');
            }

            $oldOrder = $this->orderSnapshot($units);

            $movedIndex = array_search($movedUnit, $units, true);
            array_splice($units, (int) $movedIndex, 1);

            $beforeUnit = $beforeId !== null
                ? $this->findActiveUnit($units, $beforeId)
                : null;

            $afterUnit = $afterId !== null
                ? $this->findActiveUnit($units, $afterId)
                : null;

            if (($beforeId !== null && $beforeUnit === null) || ($afterId !== null && $afterUnit === null)) {
                throw new \DomainException('Solo puedes reordenar unidades dentro de la misma dimensión.');
            }

            if ($beforeUnit !== null && $afterUnit !== null) {
                $beforeIndex = array_search($beforeUnit, $units, true);
                $afterIndex = array_search($afterUnit, $units, true);

                if ($beforeIndex !== $afterIndex + 1) {
                    throw new \DomainException('La posición seleccionada ya no es válida.');
                }
            }

            if ($beforeUnit !== null) {
                $beforeIndex = array_search($beforeUnit, $units, true);
                array_splice($units, (int) $beforeIndex, 0, [$movedUnit]);
            } elseif ($afterUnit !== null) {
                $afterIndex = array_search($afterUnit, $units, true);
                array_splice($units, (int) $afterIndex + 1, 0, [$movedUnit]);
            } else {
                $units[] = $movedUnit;
            }

            $newIds = array_map(
                static fn (MeasurementUnit $unit): int => (int) $unit->getId(),
                $units,
            );
            $oldIds = array_column($oldOrder, 'id');

            if ($oldIds === $newIds) {
                return;
            }

            foreach ($units as $index => $unit) {
                $unit->setDisplayOrder(($index + 1) * 10);
            }

            $this->auditLogger->record(
                $actor,
                'measurement_unit.reordered',
                'measurement_unit',
                $movedUnit->getId(),
                [
                    'dimension_type' => $movedUnit->getDimensionType(),
                    'active_order' => $oldOrder,
                ],
                [
                    'dimension_type' => $movedUnit->getDimensionType(),
                    'active_order' => $this->orderSnapshot($units),
                ],
            );

            $this->entityManager->flush();
        });
    }

    /** @param list<MeasurementUnit> $units */
    private function findActiveUnit(array $units, int $id): ?MeasurementUnit
    {
        foreach ($units as $unit) {
            if ($unit->getId() === $id) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * @param list<MeasurementUnit> $units
     * @return list<array{id: int, display_order: int}>
     */
    private function orderSnapshot(array $units): array
    {
        return array_map(
            static fn (MeasurementUnit $unit): array => [
                'id' => (int) $unit->getId(),
                'display_order' => $unit->getDisplayOrder(),
            ],
            $units,
        );
    }

    private function validateTechnicalConfiguration(?MeasurementUnit $unit, MeasurementUnitData $data): void
    {
        if ($data->dimensionType === null) {
            throw new \DomainException('Selecciona la dimensión de la unidad de medida.');
        }

        if ($unit !== null && strtoupper($unit->getCode()) === 'M2') {
            if (strtoupper(trim((string) $data->code)) !== 'M2') {
                throw new \DomainException('El código M2 está protegido porque el cotizador lo utiliza para identificar cobros por metro cuadrado.');
            }

            if (
                $data->dimensionType->value !== 'AREA'
                || $data->baseUnit !== null
                || abs((float) $data->conversionFactor - 1.0) > 0.000000000001
            ) {
                throw new \DomainException('La unidad M2 debe conservarse como unidad base del área con factor de conversión 1.');
            }
        }

        if ($data->dimensionType->value === 'COUNT' && $data->baseUnit !== null) {
            throw new \DomainException('Las unidades de conteo o presentación no deben declarar una conversión universal.');
        }

        if ($data->baseUnit !== null) {
            if (!$data->baseUnit->isActive() && $data->baseUnit !== $unit?->getBaseUnit()) {
                throw new \DomainException('La unidad base seleccionada ya no está activa.');
            }

            if ($unit !== null && $data->baseUnit->getId() === $unit->getId()) {
                throw new \DomainException('Una unidad no puede utilizarse a sí misma como unidad base.');
            }

            if ($data->baseUnit->getDimensionType() !== $data->dimensionType->value) {
                throw new \DomainException('La unidad base debe pertenecer a la misma dimensión.');
            }

            if ($data->baseUnit->getBaseUnit() !== null) {
                throw new \DomainException('Selecciona una unidad base principal, no una unidad que ya depende de otra.');
            }
        } elseif (abs((float) $data->conversionFactor - 1.0) > 0.000000000001) {
            throw new \DomainException('Sin unidad base, el factor de conversión debe ser 1.');
        }

        if (
            $unit !== null
            && $unit->getDimensionType() !== $data->dimensionType->value
            && $this->measurementUnitRepository->hasDerivedUnits($unit)
        ) {
            throw new \DomainException('No puedes cambiar la dimensión mientras existan unidades que dependan de esta unidad base.');
        }

        if (
            $unit !== null
            && $data->baseUnit !== null
            && $this->measurementUnitRepository->hasDerivedUnits($unit)
        ) {
            throw new \DomainException('Una unidad que sirve como base de otras unidades no puede depender a su vez de otra unidad base.');
        }
    }

    private function applyData(MeasurementUnit $unit, MeasurementUnitData $data): void
    {
        $unit
            ->setCode((string) $data->code)
            ->setName((string) $data->name)
            ->setSymbol((string) $data->symbol)
            ->setBaseUnit(null)
            ->setDimensionType($data->dimensionType ?? throw new \LogicException('La dimensión es obligatoria.'))
            ->setBaseUnit($data->baseUnit)
            ->setConversionFactor($data->baseUnit === null ? '1' : $data->conversionFactor)
            ->setAllowsFraction($data->allowsFraction)
            ->setDecimalScale($data->allowsFraction ? $data->decimalScale : 0)
            ->setDisplayOrder($data->displayOrder);
    }

    /** @return array<string, bool|int|string|null> */
    private function snapshot(MeasurementUnit $unit): array
    {
        return [
            'code' => $unit->getCode(),
            'name' => $unit->getName(),
            'symbol' => $unit->getSymbol(),
            'dimension_type' => $unit->getDimensionType(),
            'base_unit_id' => $unit->getBaseUnit()?->getId(),
            'conversion_factor' => $unit->getConversionFactor(),
            'decimal_scale' => $unit->getDecimalScale(),
            'allows_fraction' => $unit->allowsFraction(),
            'display_order' => $unit->getDisplayOrder(),
            'is_active' => $unit->isActive(),
        ];
    }
}
