<?php

declare(strict_types=1);

namespace App\Application\Equipment;

use App\Entity\Equipment\Equipment;
use App\Entity\Operations\Operation;
use App\Entity\Users\User;
use App\Enum\Equipment\EquipmentStatus;
use App\Repository\Production\ProductionReservationRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class EquipmentManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly ProductionReservationRepository $reservationRepository,
    ) {
    }

    public function create(EquipmentData $data, User $actor): Equipment
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): Equipment {
            $operation = $this->lockOperation($data->primaryOperation);
            $this->assertOperationAvailableForFutureExecution($operation);

            $equipment = new Equipment();
            $this->applyData($equipment, $data, $operation);
            $equipment->validateCapacity($actor, $data->capacityValidated);

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

            $capacityChanged = $this->capacitySnapshot($equipment) !== $this->capacityDataSnapshot($data);
            $validationChanged = ($equipment->getCapacityValidatedAt() !== null) !== $data->capacityValidated;
            if (($capacityChanged || $validationChanged) && $this->reservationRepository->hasActiveCalculatedForEquipment($equipment, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
                throw new \DomainException('No puedes cambiar ni retirar la capacidad validada mientras existan reservas con estimación calculada en esta máquina.');
            }

            $oldValues = $this->snapshot($equipment);
            $this->applyData($equipment, $data, $operation);
            if ($capacityChanged || $validationChanged) {
                $equipment->validateCapacity($actor, $data->capacityValidated);
            }
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
            ->setCapacityUnit($data->capacityUnit)
            ->setCapacityRate($data->capacityRate)
            ->setSetupMinutes($data->setupMinutes ?? 0)
            ->setCleanupMinutes($data->cleanupMinutes ?? 0)
            ->setEfficiencyPercent($data->efficiencyPercent === '' ? '100' : $data->efficiencyPercent)
            ->setCapacityValidFrom($this->parseCapacityDate($data->capacityValidFrom))
            ->setCapacityValidUntil($this->parseCapacityDate($data->capacityValidUntil))
            ->setColorConfiguration($data->colorConfiguration)
            ->setObservations($data->observations);

        if ($data->capacityValidated) {
            if ($data->capacityUnit === null || $data->capacityRate === null || trim($data->capacityRate) === '' || $data->capacityValidFrom === null) {
                throw new \DomainException('Para validar el rendimiento indica unidad, valor y fecha de inicio.');
            }
            $from = $this->parseCapacityDate($data->capacityValidFrom);
            $until = $this->parseCapacityDate($data->capacityValidUntil);
            if ($from === null || ($until !== null && $until < $from)) {
                throw new \DomainException('La vigencia del rendimiento no es válida.');
            }
        }
    }

    private function parseCapacityDate(?string $date): ?\DateTimeImmutable
    {
        $date = trim((string) $date);
        if ($date === '') { return null; }
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('America/Mexico_City'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($value === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \DomainException('La fecha de vigencia del rendimiento no es válida.');
        }
        return $value;
    }

    /** @return array<string, string|int|null> */
    private function capacitySnapshot(Equipment $equipment): array
    {
        return [
            'unit' => $equipment->getCapacityUnit()?->value,
            'rate' => $this->decimalForComparison($equipment->getCapacityRate(), 4),
            'usable_width_cm' => $this->decimalForComparison($equipment->getUsableWidthCm(), 2),
            'setup_minutes' => $equipment->getSetupMinutes(),
            'cleanup_minutes' => $equipment->getCleanupMinutes(),
            'efficiency_percent' => $this->decimalForComparison($equipment->getEfficiencyPercent(), 2),
            'valid_from' => $equipment->getCapacityValidFrom()?->format('Y-m-d'),
            'valid_until' => $equipment->getCapacityValidUntil()?->format('Y-m-d'),
        ];
    }

    /** @return array<string, string|int|null> */
    private function capacityDataSnapshot(EquipmentData $data): array
    {
        return [
            'unit' => $data->capacityUnit?->value,
            'rate' => $this->decimalForComparison($data->capacityRate, 4),
            'usable_width_cm' => $this->decimalForComparison($data->usableWidthCm, 2),
            'setup_minutes' => $data->setupMinutes ?? 0,
            'cleanup_minutes' => $data->cleanupMinutes ?? 0,
            'efficiency_percent' => $this->decimalForComparison($data->efficiencyPercent === '' ? '100' : $data->efficiencyPercent, 2),
            'valid_from' => trim((string) $data->capacityValidFrom) !== '' ? trim((string) $data->capacityValidFrom) : null,
            'valid_until' => trim((string) $data->capacityValidUntil) !== '' ? trim((string) $data->capacityValidUntil) : null,
        ];
    }

    private function decimalForComparison(?string $value, int $scale): ?string
    {
        $value = trim(str_replace(',', '.', (string) $value));
        return $value === '' || !is_numeric($value) ? null : number_format((float) $value, $scale, '.', '');
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
            'capacity' => [
                'unit' => $equipment->getCapacityUnit()?->value,
                'rate' => $equipment->getCapacityRate(),
                'setup_minutes' => $equipment->getSetupMinutes(),
                'cleanup_minutes' => $equipment->getCleanupMinutes(),
                'efficiency_percent' => $equipment->getEfficiencyPercent(),
                'valid_from' => $equipment->getCapacityValidFrom()?->format('Y-m-d'),
                'valid_until' => $equipment->getCapacityValidUntil()?->format('Y-m-d'),
                'validated_at' => $equipment->getCapacityValidatedAt()?->format(\DATE_ATOM),
                'validated_by' => $equipment->getCapacityValidatedBy()?->getId(),
            ],
            'color_configuration' => $equipment->getColorConfiguration(),
            'observations' => $equipment->getObservations(),
            'status' => $equipment->getStatus()->value,
        ];
    }
}
