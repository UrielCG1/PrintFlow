<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Users\User;
use App\Repository\Catalog\CommercialItemRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\Catalog\MeasurementUnitRepository;
use App\Repository\Materials\MaterialRepository;

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
            $unit = new MeasurementUnit();
            $this->applyData($unit, $data);

            $this->entityManager->persist($unit);
            $this->entityManager->flush();

            $this->auditLogger->record($actor, 'measurement_unit.created', 'measurement_unit', $unit->getId(), null, $this->snapshot($unit));
            $this->entityManager->flush();

            return $unit;
        });
    }

    public function update(MeasurementUnit $unit, MeasurementUnitData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($unit, $data, $actor): void {
            $oldValues = $this->snapshot($unit);
            $this->applyData($unit, $data);
            $newValues = $this->snapshot($unit);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record($actor, 'measurement_unit.updated', 'measurement_unit', $unit->getId(), $oldValues, $newValues);
            $this->entityManager->flush();
        });
    }

    public function setActive(MeasurementUnit $unit, bool $isActive, User $actor): void
    {
        if ($unit->isActive() === $isActive) {
            return;
        }

        if (!$isActive && $this->commercialItemRepository->hasActiveForMeasurementUnit($unit)) {
            throw new \DomainException('No puedes desactivar una unidad de medida que tiene conceptos comerciales activos.');
        }

        if (
            !$isActive
            && $this->materialRepository->hasActiveForMeasurementUnit($unit)
        ) {
            throw new \DomainException(
                'No puedes desactivar una unidad de medida que tiene materiales operativos activos.',
            );
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

    public function reorderActive(
        int $movedId,
        ?int $beforeId,
        ?int $afterId,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($movedId, $beforeId, $afterId, $actor): void {
            $categories = $this->measurementUnitRepository->findActiveOrderedForUpdate();
            $movedCategory = $this->findActiveCategory($categories, $movedId);

            if ($movedCategory === null) {
                throw new \DomainException('La categoría que intentas reordenar ya no está disponible.');
            }

            $oldOrder = $this->orderSnapshot($categories);

            $movedIndex = array_search($movedCategory, $categories, true);
            array_splice($categories, $movedIndex, 1);

            $beforeCategory = $beforeId !== null
                ? $this->findActiveCategory($categories, $beforeId)
                : null;

            $afterCategory = $afterId !== null
                ? $this->findActiveCategory($categories, $afterId)
                : null;

            if (($beforeId !== null && $beforeCategory === null) || ($afterId !== null && $afterCategory === null)) {
                throw new \DomainException('El orden cambió antes de poder guardar tu movimiento. Inténtalo de nuevo.');
            }

            if ($beforeCategory !== null && $afterCategory !== null) {
                $beforeIndex = array_search($beforeCategory, $categories, true);
                $afterIndex = array_search($afterCategory, $categories, true);

                if ($beforeIndex !== $afterIndex + 1) {
                    throw new \DomainException('La posición seleccionada ya no es válida.');
                }
            }

            if ($beforeCategory !== null) {
                $beforeIndex = array_search($beforeCategory, $categories, true);
                array_splice($categories, $beforeIndex, 0, [$movedCategory]);
            } elseif ($afterCategory !== null) {
                $afterIndex = array_search($afterCategory, $categories, true);
                array_splice($categories, $afterIndex + 1, 0, [$movedCategory]);
            } else {
                $categories[] = $movedCategory;
            }

            $newIds = array_map(
                static fn (MeasurementUnit $category): int => $category->getId(),
                $categories,
            );

            $oldIds = array_column($oldOrder, 'id');

            if ($oldIds === $newIds) {
                return;
            }

            foreach ($categories as $index => $category) {
                $category->setDisplayOrder($index + 1);
            }

            $this->auditLogger->record(
                $actor,
                'measurement_unit.reordered',
                'measurement_unit',
                $movedCategory->getId(),
                ['active_order' => $oldOrder],
                ['active_order' => $this->orderSnapshot($categories)],
            );

            $this->entityManager->flush();
        });
    }

    /**
     * @param list<MeasurementUnit> $categories
     */
    private function findActiveCategory(array $categories, int $id): ?MeasurementUnit
    {
        foreach ($categories as $category) {
            if ($category->getId() === $id) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @param list<MeasurementUnit> $categories
     *
     * @return list<array{id: int, display_order: int}>
     */
    private function orderSnapshot(array $categories): array
    {
        return array_map(
            static fn (MeasurementUnit $category): array => [
                'id' => $category->getId(),
                'display_order' => $category->getDisplayOrder(),
            ],
            $categories,
        );
    }

    private function applyData(MeasurementUnit $unit, MeasurementUnitData $data): void
    {
        $unit
            ->setCode((string) $data->code)
            ->setName((string) $data->name)
            ->setDisplayOrder($data->displayOrder);
    }

    /** @return array<string, bool|int|string> */
    private function snapshot(MeasurementUnit $unit): array
    {
        return [
            'code' => $unit->getCode(),
            'name' => $unit->getName(),
            'display_order' => $unit->getDisplayOrder(),
            'is_active' => $unit->isActive(),
        ];
    }
}