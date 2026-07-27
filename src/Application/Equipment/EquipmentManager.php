<?php

declare(strict_types=1);

namespace App\Application\Equipment;

use App\Entity\Equipment\Equipment;
use App\Entity\Operations\Operation;
use App\Entity\Users\User;
use App\Enum\Equipment\EquipmentStatus;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class EquipmentManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(EquipmentData $data, User $actor): Equipment
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): Equipment {
            $operation = $this->lockOperation($data->primaryOperation);
            $this->assertOperationAvailableForFutureExecution($operation);

            $equipment = new Equipment();
            $this->applyData($equipment, $data, $operation);

            $this->entityManager->persist($equipment);
            $this->entityManager->flush();

            $this->auditLogger->record(
                $actor,
                'equipment.created',
                'equipment',
                $equipment->getId(),
                null,
                $this->snapshot($equipment),
            );
            $this->entityManager->flush();

            return $equipment;
        });
    }

    public function update(Equipment $equipment, EquipmentData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($equipment, $data, $actor): void {
            $this->entityManager->lock($equipment, LockMode::PESSIMISTIC_WRITE);
            $operation = $this->lockOperation($data->primaryOperation);

            if ($equipment->isSelectableForFutureExecution()) {
                $this->assertOperationAvailableForFutureExecution($operation);
            }

            $oldValues = $this->snapshot($equipment);
            $this->applyData($equipment, $data, $operation);
            $newValues = $this->snapshot($equipment);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record(
                $actor,
                'equipment.updated',
                'equipment',
                $equipment->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function changeStatus(Equipment $equipment, EquipmentStatus $targetStatus, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($equipment, $targetStatus, $actor): void {
            $this->entityManager->lock($equipment, LockMode::PESSIMISTIC_WRITE);

            $currentStatus = $equipment->getStatus();
            if ($currentStatus === $targetStatus) {
                return;
            }

            if (!in_array($targetStatus, $currentStatus->allowedTransitions(), true)) {
                throw new \DomainException(sprintf(
                    'No puedes cambiar un equipo de "%s" a "%s".',
                    $currentStatus->label(),
                    $targetStatus->label(),
                ));
            }

            $operation = $this->lockOperation($equipment->getPrimaryOperation());
            if ($targetStatus->isSelectableForFutureExecution()) {
                $this->assertOperationAvailableForFutureExecution($operation);
            }

            $oldValues = $this->snapshot($equipment);
            $equipment->setStatus($targetStatus);

            $this->auditLogger->record(
                $actor,
                'equipment.status_changed',
                'equipment',
                $equipment->getId(),
                $oldValues,
                $this->snapshot($equipment),
            );
            $this->entityManager->flush();
        });
    }

    private function applyData(Equipment $equipment, EquipmentData $data, Operation $operation): void
    {
        $equipment
            ->setPrimaryOperation($operation)
            ->setCode((string) $data->code)
            ->setName((string) $data->name)
            ->setTechnology($data->technology)
            ->setBrand($data->brand)
            ->setModel($data->model)
            ->setSerialNumber($data->serialNumber)
            ->setUsableWidthCm($data->usableWidthCm)
            ->setTechnicalCapacity($data->technicalCapacity)
            ->setColorConfiguration($data->colorConfiguration)
            ->setObservations($data->observations);
    }

    private function lockOperation(?Operation $operation): Operation
    {
        if ($operation === null) {
            throw new \DomainException('Selecciona una operación primaria válida.');
        }

        $this->entityManager->lock($operation, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->lock($operation->getOperationArea(), LockMode::PESSIMISTIC_WRITE);

        return $operation;
    }

    private function assertOperationAvailableForFutureExecution(Operation $operation): void
    {
        if (!$operation->isActive()) {
            throw new \DomainException('No puedes usar una operación inactiva para un equipo disponible.');
        }

        if (!$operation->getOperationArea()->isActive()) {
            throw new \DomainException('No puedes usar una operación de un área inactiva para un equipo disponible.');
        }
    }

    /** @return array<string, int|string|null|array{id: int|null, code: string, name: string}> */
    private function snapshot(Equipment $equipment): array
    {
        $operation = $equipment->getPrimaryOperation();
        $area = $operation->getOperationArea();

        return [
            'code' => $equipment->getCode(),
            'name' => $equipment->getName(),
            'operation' => [
                'id' => $operation->getId(),
                'code' => $operation->getCode(),
                'name' => $operation->getName(),
            ],
            'operation_area' => [
                'id' => $area->getId(),
                'code' => $area->getCode(),
                'name' => $area->getName(),
            ],
            'technology' => $equipment->getTechnology(),
            'brand' => $equipment->getBrand(),
            'model' => $equipment->getModel(),
            'serial_number' => $equipment->getSerialNumber(),
            'usable_width_cm' => $equipment->getUsableWidthCm(),
            'technical_capacity' => $equipment->getTechnicalCapacity(),
            'color_configuration' => $equipment->getColorConfiguration(),
            'observations' => $equipment->getObservations(),
            'status' => $equipment->getStatus()->value,
        ];
    }
}