<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Entity\Operations\OperationArea;
use App\Entity\Users\User;
use App\Repository\Operations\OperationAreaRepository;
use App\Repository\Operations\OperationRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class OperationAreaManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly OperationAreaRepository $operationAreaRepository,
        private readonly OperationRepository $operationRepository,
    ) {
    }

    public function create(OperationAreaData $data, User $actor): OperationArea
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): OperationArea {
            $area = new OperationArea();
            $this->applyData($area, $data);

            $this->entityManager->persist($area);
            $this->entityManager->flush();

            $this->auditLogger->record(
                $actor,
                'operation_area.created',
                'operation_area',
                $area->getId(),
                null,
                $this->snapshot($area),
            );
            $this->entityManager->flush();

            return $area;
        });
    }

    public function update(OperationArea $area, OperationAreaData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($area, $data, $actor): void {
            $this->entityManager->lock($area, LockMode::PESSIMISTIC_WRITE);

            $oldValues = $this->snapshot($area);
            $this->applyData($area, $data);
            $newValues = $this->snapshot($area);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record(
                $actor,
                'operation_area.updated',
                'operation_area',
                $area->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function setActive(OperationArea $area, bool $isActive, User $actor): void
    {
        if ($area->isActive() === $isActive) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($area, $isActive, $actor): void {
            $this->entityManager->lock($area, LockMode::PESSIMISTIC_WRITE);

            if (!$isActive && $this->operationRepository->hasActiveForArea($area)) {
                throw new \DomainException(
                    'No puedes desactivar un área que tiene operaciones activas. Desactiva o reubica primero sus operaciones.',
                );
            }

            $oldValues = $this->snapshot($area);
            $area->setIsActive($isActive);
            $newValues = $this->snapshot($area);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'operation_area.activated' : 'operation_area.deactivated',
                'operation_area',
                $area->getId(),
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
            $areas = $this->operationAreaRepository->findActiveOrderedForUpdate();
            $movedArea = $this->findActiveArea($areas, $movedId);

            if ($movedArea === null) {
                throw new \DomainException('El área que intentas reordenar ya no está disponible. Actualiza la página e inténtalo de nuevo.');
            }

            $oldOrder = $this->orderSnapshot($areas);

            $movedIndex = array_search($movedArea, $areas, true);
            array_splice($areas, $movedIndex, 1);

            $beforeArea = $beforeId !== null ? $this->findActiveArea($areas, $beforeId) : null;
            $afterArea = $afterId !== null ? $this->findActiveArea($areas, $afterId) : null;

            if (($beforeId !== null && $beforeArea === null) || ($afterId !== null && $afterArea === null)) {
                throw new \DomainException('El orden cambió antes de poder guardar tu movimiento. Actualiza la página e inténtalo de nuevo.');
            }

            if ($beforeArea !== null && $afterArea !== null) {
                $beforeIndex = array_search($beforeArea, $areas, true);
                $afterIndex = array_search($afterArea, $areas, true);

                if ($beforeIndex !== $afterIndex + 1) {
                    throw new \DomainException('La posición seleccionada ya no es válida. Actualiza la página e inténtalo de nuevo.');
                }
            }

            if ($beforeArea !== null) {
                array_splice($areas, (int) array_search($beforeArea, $areas, true), 0, [$movedArea]);
            } elseif ($afterArea !== null) {
                array_splice($areas, (int) array_search($afterArea, $areas, true) + 1, 0, [$movedArea]);
            } else {
                $areas[] = $movedArea;
            }

            $newIds = array_map(static fn (OperationArea $area): int => (int) $area->getId(), $areas);

            if (array_column($oldOrder, 'id') === $newIds) {
                return;
            }

            foreach ($areas as $index => $area) {
                $area->setDisplayOrder($index + 1);
            }

            $this->auditLogger->record(
                $actor,
                'operation_area.reordered',
                'operation_area',
                $movedArea->getId(),
                ['active_order' => $oldOrder],
                ['active_order' => $this->orderSnapshot($areas)],
            );
            $this->entityManager->flush();
        });
    }

    /** @param list<OperationArea> $areas */
    private function findActiveArea(array $areas, int $id): ?OperationArea
    {
        foreach ($areas as $area) {
            if ($area->getId() === $id) {
                return $area;
            }
        }

        return null;
    }

    /**
     * @param list<OperationArea> $areas
     *
     * @return list<array{id: int, display_order: int}>
     */
    private function orderSnapshot(array $areas): array
    {
        return array_map(
            static fn (OperationArea $area): array => [
                'id' => (int) $area->getId(),
                'display_order' => $area->getDisplayOrder(),
            ],
            $areas,
        );
    }

    private function applyData(OperationArea $area, OperationAreaData $data): void
    {
        $area
            ->setCode((string) $data->code)
            ->setName((string) $data->name)
            ->setDescription($data->description)
            ->setDisplayOrder($data->displayOrder);
    }

    /** @return array<string, bool|int|string|null> */
    private function snapshot(OperationArea $area): array
    {
        return [
            'code' => $area->getCode(),
            'name' => $area->getName(),
            'description' => $area->getDescription(),
            'display_order' => $area->getDisplayOrder(),
            'is_active' => $area->isActive(),
        ];
    }
}