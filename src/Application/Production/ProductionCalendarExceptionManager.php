<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Production\ProductionCalendarException;
use App\Entity\Users\User;
use App\Enum\Production\ProductionCalendarExceptionType;
use App\Repository\Equipment\EquipmentRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class ProductionCalendarExceptionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly ProductionReservationRecalculator $reservationRecalculator,
        private readonly \App\Repository\Production\ProductionReservationRepository $reservationRepository,
        private readonly EquipmentRepository $equipmentRepository,
        private readonly ProductionScheduleLock $scheduleLock,
    )
    {
    }

    public function create(ProductionCalendarExceptionData $data, User $actor): ProductionCalendarException
    {
        if ($data->type === null || $data->startsAt === null || $data->endsAt === null) {
            throw new \DomainException('Selecciona el tipo de excepción y sus fechas.');
        }
        $reason = trim($data->reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new \DomainException('Indica un motivo de 5 a 500 caracteres.');
        }
        if (in_array($data->type, [ProductionCalendarExceptionType::MAINTENANCE, ProductionCalendarExceptionType::OUTAGE], true) && $data->equipment === null) {
            throw new \DomainException('Selecciona la máquina para mantenimiento o avería.');
        }
        if ($data->type === ProductionCalendarExceptionType::HOLIDAY && $data->equipment !== null) {
            throw new \DomainException('Un feriado aplica a toda la planta; deja la máquina sin seleccionar.');
        }

        $timezone = new \DateTimeZone('America/Mexico_City');
        $start = $data->startsAt->setTimezone($timezone);
        $end = $data->endsAt->setTimezone($timezone);
        if ($data->type === ProductionCalendarExceptionType::HOLIDAY) {
            $start = $start->setTime(0, 0);
            $end = $end->setTime(0, 0)->modify('+1 day');
        }
        if ($end <= $start || $end > $start->modify('+365 days')) {
            throw new \DomainException('El intervalo debe ser positivo y no puede exceder un año.');
        }

        $scheduleLockAcquired = $this->scheduleLock->acquire();
        try {
            return $this->entityManager->wrapInTransaction(function () use ($data, $actor, $start, $end, $reason): ProductionCalendarException {
            $this->lockAffectedEquipment($data->equipment);
            if ($data->type !== ProductionCalendarExceptionType::OVERTIME) {
                $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'));
                if ($this->reservationRepository->findInProgressOverlapping($start, $end, $now, $data->equipment) !== []) {
                    throw new \DomainException('No se puede bloquear una máquina mientras tiene una reserva en curso; registra el bloqueo después de que termine el segmento activo.');
                }
                if ($this->reservationRepository->findPinnedOverlapping($start, $end, $data->equipment) !== []) {
                    throw new \DomainException('Hay una reserva futura fijada que cruza ese bloqueo; libera la reserva o elige otro intervalo.');
                }
            }

            $exception = (new ProductionCalendarException())
                ->setType($data->type)
                ->setEquipment($data->equipment)
                ->setStartsAt($start)
                ->setEndsAt($end)
                ->setReason($reason)
                ->setCreatedBy($actor);
            $this->entityManager->persist($exception);
            $this->entityManager->flush();
            $this->auditLogger->record(
                actor: $actor,
                action: 'production.calendar_exception_created',
                entityType: 'production_calendar_exception',
                entityId: null,
                newValues: [
                    'type' => $exception->getType()->value,
                    'equipment_id' => $exception->getEquipment()?->getId(),
                    'starts_at' => $start->format(\DATE_ATOM),
                    'ends_at' => $end->format(\DATE_ATOM),
                    'reason' => $reason,
                ],
            );
            $this->entityManager->flush();
            $this->reservationRecalculator->recalculate($exception->getEquipment(), $start, $actor);
                return $exception;
            });
        } finally {
            $this->scheduleLock->release($scheduleLockAcquired);
        }
    }

    public function deactivate(ProductionCalendarException $exception, User $actor): void
    {
        $scheduleLockAcquired = $this->scheduleLock->acquire();
        try {
            $this->entityManager->wrapInTransaction(function () use ($exception, $actor): void {
            $this->entityManager->lock($exception, LockMode::PESSIMISTIC_WRITE);
            if (!$exception->isActive()) { return; }
            $equipment = $exception->getEquipment();
            $this->lockAffectedEquipment($equipment);
            $changedStart = $exception->getStartsAt();
            $exception->deactivate($actor);
            $this->auditLogger->record(
                actor: $actor,
                action: 'production.calendar_exception_deactivated',
                entityType: 'production_calendar_exception',
                entityId: $exception->getId(),
                newValues: ['type' => $exception->getType()->value, 'reason' => $exception->getReason()],
            );
            $this->entityManager->flush();
                $this->reservationRecalculator->recalculate($equipment, $changedStart, $actor);
            });
        } finally {
            $this->scheduleLock->release($scheduleLockAcquired);
        }
    }

    private function lockAffectedEquipment(?\App\Entity\Equipment\Equipment $equipment): void
    {
        $equipmentToLock = $equipment === null
            ? $this->equipmentRepository->findAllForCalendarException()
            : [$equipment];
        usort($equipmentToLock, static fn ($left, $right): int => $left->getId() <=> $right->getId());
        foreach ($equipmentToLock as $machine) {
            $this->entityManager->lock($machine, LockMode::PESSIMISTIC_WRITE);
        }
    }
}
