<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Entity\Operations\Operation;
use App\Entity\Operations\OperationArea;
use App\Entity\Users\User;
use App\Repository\Operations\OperationRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class OperationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly OperationRepository $operationRepository,
    ) {
    }

    public function create(OperationData $data, User $actor): Operation
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): Operation {
            $area = $this->lockArea($data->operationArea);
            $this->assertActiveArea($area);

            $operation = new Operation();
            $operation
                ->setOperationArea($area)
                ->setDisplayOrder($this->operationRepository->nextDisplayOrder($area, true));
            $this->applyData($operation, $data);

            $this->entityManager->persist($operation);
            $this->entityManager->flush();

            $this->auditLogger->record(
                $actor,
                'operation.created',
                'operation',
                $operation->getId(),
                null,
                $this->snapshot($operation),
            );
            $this->entityManager->flush();

            return $operation;
        });
    }

    public function update(Operation $operation, OperationData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($operation, $data, $actor): void {
            $this->entityManager->lock($operation, LockMode::PESSIMISTIC_WRITE);

            $sourceArea = $operation->getOperationArea();
            $targetArea = $this->lockAreas($sourceArea, $data->operationArea)[1];

            if ($sourceArea !== $targetArea && !$targetArea->isActive()) {
                throw new \DomainException('No puedes mover una operación a un área inactiva.');
            }

            if ($operation->isActive() && !$targetArea->isActive()) {
                throw new \DomainException('Una operación activa debe pertenecer a un área activa.');
            }

            $oldValues = $this->snapshot($operation);
            $isAreaChange = $sourceArea !== $targetArea;

            $sourceOperations = $operation->isActive()
                ? $this->operationRepository->findActiveOrderedForUpdate($sourceArea)
                : [];
            $targetOperations = $isAreaChange && $operation->isActive()
                ? $this->operationRepository->findActiveOrderedForUpdate($targetArea)
                : [];

            if ($isAreaChange) {
                $operation->setOperationArea($targetArea);
            }
            $this->applyData($operation, $data);

            if ($isAreaChange) {
                if ($operation->isActive()) {
                    $this->removeFromOrder($sourceOperations, $operation);
                    $this->normalizeOrder($sourceOperations);

                    $targetOperations[] = $operation;
                    $this->normalizeOrder($targetOperations);
                } else {
                    $operation->setDisplayOrder($this->operationRepository->nextDisplayOrder($targetArea));
                }
            }

            $newValues = $this->snapshot($operation);
            if ($oldValues === $newValues && !$isAreaChange) {
                return;
            }

            $this->auditLogger->record(
                $actor,
                'operation.updated',
                'operation',
                $operation->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function setActive(Operation $operation, bool $isActive, User $actor): void
    {
        if ($operation->isActive() === $isActive) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($operation, $isActive, $actor): void {
            $this->entityManager->lock($operation, LockMode::PESSIMISTIC_WRITE);
            $area = $this->lockArea($operation->getOperationArea());

            if ($isActive) {
                $this->assertActiveArea($area);
            }

            $activeOperations = $this->operationRepository->findActiveOrderedForUpdate($area);
            $oldValues = $this->snapshot($operation);
            $oldOrder = $this->orderSnapshot($activeOperations);

            if ($isActive) {
                $operation->setIsActive(true);
                $activeOperations[] = $operation;
                $this->normalizeOrder($activeOperations);
            } else {
                $this->removeFromOrder($activeOperations, $operation);
                $operation->setIsActive(false);
                $this->normalizeOrder($activeOperations);
            }

            $this->auditLogger->record(
                $actor,
                $isActive ? 'operation.activated' : 'operation.deactivated',
                'operation',
                $operation->getId(),
                [
                    'operation' => $oldValues,
                    'active_order' => $oldOrder,
                ],
                [
                    'operation' => $this->snapshot($operation),
                    'active_order' => $this->orderSnapshot($activeOperations),
                ],
            );
            $this->entityManager->flush();
        });
    }

    public function reorderActive(
        OperationArea $area,
        int $movedId,
        ?int $beforeId,
        ?int $afterId,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($area, $movedId, $beforeId, $afterId, $actor): void {
            $area = $this->lockArea($area);
            $this->assertActiveArea($area);

            $operations = $this->operationRepository->findActiveOrderedForUpdate($area);
            $movedOperation = $this->findActiveOperation($operations, $movedId);

            if ($movedOperation === null) {
                throw new \DomainException('La operación que intentas reordenar ya no está disponible en esta área.');
            }

            $oldOrder = $this->orderSnapshot($operations);
            $movedIndex = array_search($movedOperation, $operations, true);
            array_splice($operations, $movedIndex, 1);

            $beforeOperation = $beforeId !== null ? $this->findActiveOperation($operations, $beforeId) : null;
            $afterOperation = $afterId !== null ? $this->findActiveOperation($operations, $afterId) : null;

            if (($beforeId !== null && $beforeOperation === null) || ($afterId !== null && $afterOperation === null)) {
                throw new \DomainException('El orden cambió antes de poder guardar tu movimiento. Actualiza la página e inténtalo de nuevo.');
            }

            if ($beforeOperation !== null && $afterOperation !== null) {
                $beforeIndex = array_search($beforeOperation, $operations, true);
                $afterIndex = array_search($afterOperation, $operations, true);

                if ($beforeIndex !== $afterIndex + 1) {
                    throw new \DomainException('La posición seleccionada ya no es válida. Actualiza la página e inténtalo de nuevo.');
                }
            }

            if ($beforeOperation !== null) {
                array_splice($operations, (int) array_search($beforeOperation, $operations, true), 0, [$movedOperation]);
            } elseif ($afterOperation !== null) {
                array_splice($operations, (int) array_search($afterOperation, $operations, true) + 1, 0, [$movedOperation]);
            } else {
                $operations[] = $movedOperation;
            }

            if (array_column($oldOrder, 'id') === array_map(static fn (Operation $operation): int => (int) $operation->getId(), $operations)) {
                return;
            }

            $this->normalizeOrder($operations);

            $this->auditLogger->record(
                $actor,
                'operation.reordered',
                'operation',
                $movedOperation->getId(),
                [
                    'operation_area' => $this->areaSnapshot($area),
                    'active_order' => $oldOrder,
                ],
                [
                    'operation_area' => $this->areaSnapshot($area),
                    'active_order' => $this->orderSnapshot($operations),
                ],
            );
            $this->entityManager->flush();
        });
    }

    private function lockArea(?OperationArea $area): OperationArea
    {
        if ($area === null) {
            throw new \DomainException('Selecciona un área operativa válida.');
        }

        $this->entityManager->lock($area, LockMode::PESSIMISTIC_WRITE);

        return $area;
    }

    /** @return array{0: OperationArea, 1: OperationArea} */
    private function lockAreas(OperationArea $first, ?OperationArea $second): array
    {
        $second = $second ?? throw new \DomainException('Selecciona un área operativa válida.');
        $areas = [$first, $second];

        usort($areas, static fn (OperationArea $left, OperationArea $right): int => (int) $left->getId() <=> (int) $right->getId());
        foreach ($areas as $area) {
            $this->entityManager->lock($area, LockMode::PESSIMISTIC_WRITE);
        }

        return [$first, $second];
    }

    private function assertActiveArea(OperationArea $area): void
    {
        if (!$area->isActive()) {
            throw new \DomainException('El área operativa seleccionada está inactiva.');
        }
    }

    /** @param list<Operation> $operations */
    private function findActiveOperation(array $operations, int $id): ?Operation
    {
        foreach ($operations as $operation) {
            if ($operation->getId() === $id) {
                return $operation;
            }
        }

        return null;
    }

    /** @param list<Operation> $operations */
    private function removeFromOrder(array &$operations, Operation $operation): void
    {
        $index = array_search($operation, $operations, true);
        if ($index !== false) {
            array_splice($operations, $index, 1);
        }
    }

    /** @param list<Operation> $operations */
    private function normalizeOrder(array $operations): void
    {
        foreach ($operations as $index => $operation) {
            $operation->setDisplayOrder($index + 1);
        }
    }

    /** @param list<Operation> $operations
     *  @return list<array{id: int, display_order: int}> */
    private function orderSnapshot(array $operations): array
    {
        return array_map(
            static fn (Operation $operation): array => [
                'id' => (int) $operation->getId(),
                'display_order' => $operation->getDisplayOrder(),
            ],
            $operations,
        );
    }

    private function applyData(Operation $operation, OperationData $data): void
    {
        $operation
            ->setCode((string) $data->code)
            ->setName((string) $data->name)
            ->setDescription($data->description);
    }

    /** @return array<string, bool|int|string|null|array{id: int|null, code: string, name: string}> */
    private function snapshot(Operation $operation): array
    {
        return [
            'operation_area' => $this->areaSnapshot($operation->getOperationArea()),
            'code' => $operation->getCode(),
            'name' => $operation->getName(),
            'description' => $operation->getDescription(),
            'display_order' => $operation->getDisplayOrder(),
            'is_active' => $operation->isActive(),
        ];
    }

    /** @return array{id: int|null, code: string, name: string} */
    private function areaSnapshot(OperationArea $area): array
    {
        return [
            'id' => $area->getId(),
            'code' => $area->getCode(),
            'name' => $area->getName(),
        ];
    }
}