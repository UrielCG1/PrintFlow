<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Entity\Equipment\Equipment;
use App\Entity\Operations\Operation;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Quotations\Quotation;
use App\Entity\Quotations\QuotationItem;
use App\Entity\Users\User;
use App\Enum\Quotations\QuotationStatus;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Orders\ServiceOrderRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderFolioGenerator;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class ServiceOrderManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceOrderRepository $serviceOrderRepository,
        private readonly ServiceOrderOperationPlanRepository $operationPlanRepository,
        private readonly ServiceOrderFolioGenerator $folioGenerator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function createFromAcceptedQuotation(Quotation $quotation, User $actor): ServiceOrder
    {
        if ($quotation->getId() === null) {
            throw new \LogicException('No es posible crear una orden desde una cotización sin identificar.');
        }

        return $this->entityManager->wrapInTransaction(
            function () use ($quotation, $actor): ServiceOrder {
                /*
                 * La cotización se bloquea, no la pantalla. Así se impide que
                 * dos solicitudes creen dos órdenes a partir del mismo origen.
                 */
                $this->entityManager->refresh($quotation, LockMode::PESSIMISTIC_WRITE);

                if ($quotation->getStatus() !== QuotationStatus::ACCEPTED) {
                    throw new \DomainException('Solo una cotización aceptada puede convertirse en una orden de servicio.');
                }

                if (!$quotation->hasBeenIssued() || $quotation->getFolio() === null) {
                    throw new \DomainException('La cotización aceptada debe contar con un folio emitido.');
                }

                if ($this->serviceOrderRepository->findOneBySourceQuotation($quotation) !== null) {
                    throw new \DomainException('Esta cotización ya tiene una orden de servicio asociada.');
                }

                if ($quotation->getItems()->isEmpty()) {
                    throw new \DomainException('La cotización aceptada no tiene partidas para convertir en una orden.');
                }

                $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $serviceOrder = (new ServiceOrder())
                    ->setSourceQuotation($quotation)
                    ->setCreatedBy($actor)
                    ->setFolio($this->folioGenerator->next($createdAt))
                    ->setSourceQuotationFolio($quotation->getFolio())
                    ->setQuotationSnapshot($this->quotationSnapshot($quotation))
                    ->setClientSnapshot($quotation->getClientSnapshot())
                    ->setFiscalAddressSnapshot($quotation->getFiscalAddressSnapshot())
                    ->setDeliveryAddressSnapshot($quotation->getDeliveryAddressSnapshot())
                    ->setNotes($quotation->getNotes())
                    ->setCurrency($quotation->getCurrency())
                    ->setDiscountPercent($quotation->getDiscountPercent())
                    ->setTaxRate($quotation->getTaxRate())
                    ->setTotals(
                        $quotation->getSubtotal(),
                        $quotation->getDiscountAmount(),
                        $quotation->getTaxableAmount(),
                        $quotation->getTaxAmount(),
                        $quotation->getTotal(),
                    );

                foreach ($quotation->getItems() as $quotationItem) {
                    $serviceOrder->addItem($this->copyItem($quotationItem));
                }

                $this->entityManager->persist($serviceOrder);
                $this->entityManager->flush();

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'service_order.created_from_quotation',
                    entityType: 'service_order',
                    entityId: $serviceOrder->getId(),
                    newValues: $this->auditSnapshot($serviceOrder),
                );
                $this->entityManager->flush();

                return $serviceOrder;
            },
        );
    }

    public function updatePlanning(
        ServiceOrder $serviceOrder,
        ServiceOrderPlanningData $data,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $data, $actor): void {
            $this->lockServiceOrder($serviceOrder);
            $this->assertPendingPlanning($serviceOrder);

            $commitmentDate = $this->normalizeCommitmentDate($data->commitmentDate);
            $oldValues = $this->planningSnapshot($serviceOrder);
            $serviceOrder->setCommitmentDate($commitmentDate);
            $newValues = $this->planningSnapshot($serviceOrder);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'service_order.commitment_date_updated',
                entityType: 'service_order',
                entityId: $serviceOrder->getId(),
                oldValues: $oldValues,
                newValues: $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function addOperationToItem(
        ServiceOrder $serviceOrder,
        ServiceOrderItem $serviceOrderItem,
        ServiceOrderItemOperationData $data,
        User $actor,
    ): ServiceOrderOperationPlan {
        return $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $serviceOrderItem, $data, $actor): ServiceOrderOperationPlan {
            $this->lockServiceOrder($serviceOrder);
            $this->assertPendingPlanning($serviceOrder);
            $this->lockAndAssertOrderItem($serviceOrder, $serviceOrderItem);

            $operation = $this->lockOperation($data->operation);
            $this->assertOperationAvailableForPlanning($operation);

            $existingPlan = $this->operationPlanRepository->findOneByItemAndOperationForUpdate($serviceOrderItem, $operation);
            if ($existingPlan !== null && $existingPlan->isActive()) {
                throw new \DomainException('Esta operación ya está agregada a la ruta de la partida.');
            }

            $plans = $this->operationPlanRepository->findActiveForItemForUpdate($serviceOrderItem);
            $nextSequence = $plans === []
                ? 1
                : max(array_map(static fn (ServiceOrderOperationPlan $plan): int => $plan->getSequenceNumber(), $plans)) + 1;

            if ($existingPlan !== null) {
                $oldValues = $this->operationPlanSnapshot($existingPlan);
                $existingPlan
                    ->reactivate()
                    ->setSequenceNumber($nextSequence)
                    ->setOperationSnapshot($this->operationSnapshot($operation));

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'service_order.operation_reactivated',
                    entityType: 'service_order_operation_plan',
                    entityId: $existingPlan->getId(),
                    oldValues: $oldValues,
                    newValues: $this->operationPlanSnapshot($existingPlan),
                );
                $this->entityManager->flush();

                return $existingPlan;
            }

            $plan = (new ServiceOrderOperationPlan())
                ->setServiceOrderItem($serviceOrderItem)
                ->setOperation($operation)
                ->setCreatedBy($actor)
                ->setSequenceNumber($nextSequence)
                ->setOperationSnapshot($this->operationSnapshot($operation));
            $serviceOrderItem->addOperationPlan($plan);

            $this->entityManager->persist($plan);
            $this->entityManager->flush();

            $this->auditLogger->record(
                actor: $actor,
                action: 'service_order.operation_added',
                entityType: 'service_order_operation_plan',
                entityId: $plan->getId(),
                newValues: $this->operationPlanSnapshot($plan),
            );
            $this->entityManager->flush();

            return $plan;
        });
    }

    public function assignEquipmentToOperation(
        ServiceOrder $serviceOrder,
        ServiceOrderOperationPlan $plan,
        ServiceOrderItemOperationEquipmentData $data,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $plan, $data, $actor): void {
            $this->lockServiceOrder($serviceOrder);
            $this->assertPendingPlanning($serviceOrder);
            $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
            $this->assertPlanBelongsToOrder($serviceOrder, $plan);

            if (!$plan->isActive()) {
                throw new \DomainException('No puedes asignar equipo a una operación retirada de la ruta.');
            }

            $operation = $this->lockOperation($plan->getOperation());
            $this->assertOperationAvailableForPlanning($operation);

            $equipment = $data->equipment;
            if ($equipment !== null) {
                $this->entityManager->lock($equipment, LockMode::PESSIMISTIC_WRITE);
                $this->assertEquipmentAvailableForOperation($equipment, $operation);
            }

            $oldValues = $this->operationPlanSnapshot($plan);
            $plan->setEquipment($equipment, $equipment !== null ? $this->equipmentSnapshot($equipment) : null);
            $newValues = $this->operationPlanSnapshot($plan);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'service_order.operation_equipment_updated',
                entityType: 'service_order_operation_plan',
                entityId: $plan->getId(),
                oldValues: $oldValues,
                newValues: $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function removeOperationFromItem(
        ServiceOrder $serviceOrder,
        ServiceOrderOperationPlan $plan,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $plan, $actor): void {
            $this->lockServiceOrder($serviceOrder);
            $this->assertPendingPlanning($serviceOrder);
            $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
            $this->assertPlanBelongsToOrder($serviceOrder, $plan);

            if (!$plan->isActive()) {
                throw new \DomainException('La operación ya no está activa en esta ruta.');
            }

            $oldValues = $this->operationPlanSnapshot($plan);
            $plan->deactivate($actor);

            $this->auditLogger->record(
                actor: $actor,
                action: 'service_order.operation_removed',
                entityType: 'service_order_operation_plan',
                entityId: $plan->getId(),
                oldValues: $oldValues,
                newValues: $this->operationPlanSnapshot($plan),
            );
            $this->entityManager->flush();
        });
    }

    public function reorderItemOperations(
        ServiceOrder $serviceOrder,
        ServiceOrderItem $serviceOrderItem,
        int $movedId,
        ?int $beforeId,
        ?int $afterId,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $serviceOrderItem, $movedId, $beforeId, $afterId, $actor): void {
            $this->lockServiceOrder($serviceOrder);
            $this->assertPendingPlanning($serviceOrder);
            $this->lockAndAssertOrderItem($serviceOrder, $serviceOrderItem);

            $plans = $this->operationPlanRepository->findActiveForItemForUpdate($serviceOrderItem);
            $movedPlan = $this->findPlan($plans, $movedId);
            if ($movedPlan === null) {
                throw new \DomainException('La operación que intentas reordenar ya no está disponible en esta partida.');
            }

            $oldOrder = $this->operationOrderSnapshot($plans);
            $movedIndex = array_search($movedPlan, $plans, true);
            array_splice($plans, $movedIndex, 1);

            $beforePlan = $beforeId !== null ? $this->findPlan($plans, $beforeId) : null;
            $afterPlan = $afterId !== null ? $this->findPlan($plans, $afterId) : null;
            if (($beforeId !== null && $beforePlan === null) || ($afterId !== null && $afterPlan === null)) {
                throw new \DomainException('La ruta cambió antes de guardar tu movimiento. Actualiza la página e inténtalo de nuevo.');
            }

            if ($beforePlan !== null && $afterPlan !== null) {
                $beforeIndex = array_search($beforePlan, $plans, true);
                $afterIndex = array_search($afterPlan, $plans, true);
                if ($beforeIndex !== $afterIndex + 1) {
                    throw new \DomainException('La posición seleccionada ya no es válida. Actualiza la página e inténtalo de nuevo.');
                }
            }

            if ($beforePlan !== null) {
                array_splice($plans, (int) array_search($beforePlan, $plans, true), 0, [$movedPlan]);
            } elseif ($afterPlan !== null) {
                array_splice($plans, (int) array_search($afterPlan, $plans, true) + 1, 0, [$movedPlan]);
            } else {
                $plans[] = $movedPlan;
            }

            if (array_column($oldOrder, 'id') === array_map(static fn (ServiceOrderOperationPlan $plan): int => (int) $plan->getId(), $plans)) {
                return;
            }

            foreach ($plans as $index => $plan) {
                $plan->setSequenceNumber($index + 1);
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'service_order.operation_reordered',
                entityType: 'service_order_item',
                entityId: $serviceOrderItem->getId(),
                oldValues: ['service_order_folio' => $serviceOrder->getFolio(), 'operations' => $oldOrder],
                newValues: ['service_order_folio' => $serviceOrder->getFolio(), 'operations' => $this->operationOrderSnapshot($plans)],
            );
            $this->entityManager->flush();
        });
    }

    public function markPlanned(ServiceOrder $serviceOrder, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $actor): void {
            $this->lockServiceOrder($serviceOrder);
            $this->assertPendingPlanning($serviceOrder);

            if ($serviceOrder->getCommitmentDate() === null) {
                throw new \DomainException('Asigna la fecha compromiso antes de marcar la orden como planificada.');
            }

            foreach ($serviceOrder->getItems() as $serviceOrderItem) {
                $plans = $this->operationPlanRepository->findActiveForItemForUpdate($serviceOrderItem);
                if ($plans === []) {
                    throw new \DomainException(sprintf('La partida %d debe tener al menos una operación planificada.', $serviceOrderItem->getLineNumber()));
                }

                foreach ($plans as $plan) {
                    $operation = $this->lockOperation($plan->getOperation());
                    $this->assertOperationAvailableForPlanning($operation);
                    if ($plan->getEquipment() !== null) {
                        $equipment = $plan->getEquipment();
                        $this->entityManager->lock($equipment, LockMode::PESSIMISTIC_WRITE);
                        $this->assertEquipmentAvailableForOperation($equipment, $operation);
                    }
                }
            }

            $oldValues = $this->planningSnapshot($serviceOrder);
            $serviceOrder->markPlanned($actor);

            $this->auditLogger->record(
                actor: $actor,
                action: 'service_order.planned',
                entityType: 'service_order',
                entityId: $serviceOrder->getId(),
                oldValues: $oldValues,
                newValues: $this->planningSnapshot($serviceOrder),
            );
            $this->entityManager->flush();
        });
    }

    private function lockServiceOrder(ServiceOrder $serviceOrder): void
    {
        $this->entityManager->lock($serviceOrder, LockMode::PESSIMISTIC_WRITE);
    }

    private function assertPendingPlanning(ServiceOrder $serviceOrder): void
    {
        if (!$serviceOrder->isPendingPlanning()) {
            throw new \DomainException('La planificación está cerrada para esta orden.');
        }
    }

    private function lockAndAssertOrderItem(ServiceOrder $serviceOrder, ServiceOrderItem $serviceOrderItem): void
    {
        $this->entityManager->lock($serviceOrderItem, LockMode::PESSIMISTIC_WRITE);

        if ($serviceOrderItem->getServiceOrder()->getId() !== $serviceOrder->getId()) {
            throw new \DomainException('La partida no pertenece a esta orden de servicio.');
        }
    }

    private function assertPlanBelongsToOrder(ServiceOrder $serviceOrder, ServiceOrderOperationPlan $plan): void
    {
        if ($plan->getServiceOrderItem()->getServiceOrder()->getId() !== $serviceOrder->getId()) {
            throw new \DomainException('La operación planificada no pertenece a esta orden de servicio.');
        }
    }

    private function lockOperation(?Operation $operation): Operation
    {
        if ($operation === null) {
            throw new \DomainException('Selecciona una operación válida.');
        }

        $this->entityManager->lock($operation, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->lock($operation->getOperationArea(), LockMode::PESSIMISTIC_WRITE);

        return $operation;
    }

    private function assertOperationAvailableForPlanning(Operation $operation): void
    {
        if (!$operation->isActive() || !$operation->getOperationArea()->isActive()) {
            throw new \DomainException('La operación seleccionada ya no está activa para planificar órdenes nuevas.');
        }
    }

    private function assertEquipmentAvailableForOperation(Equipment $equipment, Operation $operation): void
    {
        if (!$equipment->isSelectableForFutureExecution()) {
            throw new \DomainException(sprintf('El equipo %s no está disponible para planificación.', $equipment->getCode()));
        }

        if ($equipment->getPrimaryOperation()->getId() !== $operation->getId()) {
            throw new \DomainException('El equipo seleccionado no corresponde a la operación planificada.');
        }

        $this->assertOperationAvailableForPlanning($equipment->getPrimaryOperation());
    }

    private function normalizeCommitmentDate(?string $date): ?\DateTimeImmutable
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }

        $timezone = new \DateTimeZone('America/Mexico_City');
        $commitmentDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $commitmentDate === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new \DomainException('La fecha compromiso no es válida.');
        }

        return $commitmentDate;
    }

    /** @return array<string, int|string> */
    private function operationSnapshot(Operation $operation): array
    {
        return [
            'id' => (int) $operation->getId(),
            'code' => $operation->getCode(),
            'name' => $operation->getName(),
            'area_id' => (int) $operation->getOperationArea()->getId(),
            'area_code' => $operation->getOperationArea()->getCode(),
            'area_name' => $operation->getOperationArea()->getName(),
        ];
    }

    /** @return array<string, int|string> */
    private function equipmentSnapshot(Equipment $equipment): array
    {
        return [
            'id' => (int) $equipment->getId(),
            'code' => $equipment->getCode(),
            'name' => $equipment->getName(),
            'primary_operation_id' => (int) $equipment->getPrimaryOperation()->getId(),
            'primary_operation_code' => $equipment->getPrimaryOperation()->getCode(),
            'primary_operation_name' => $equipment->getPrimaryOperation()->getName(),
        ];
    }

    /** @return array<string, bool|int|string|null|array<string, mixed>> */
    private function operationPlanSnapshot(ServiceOrderOperationPlan $plan): array
    {
        return [
            'service_order_item_id' => $plan->getServiceOrderItem()->getId(),
            'line_number' => $plan->getServiceOrderItem()->getLineNumber(),
            'operation' => $plan->getOperationSnapshot(),
            'equipment' => $plan->getEquipmentSnapshot(),
            'sequence_number' => $plan->getSequenceNumber(),
            'is_active' => $plan->isActive(),
            'deactivated_at' => $plan->getDeactivatedAt()?->format(\DATE_ATOM),
        ];
    }

    /** @param list<ServiceOrderOperationPlan> $plans
     * @return list<array{id: int, sequence_number: int, operation_code: string}> */
    private function operationOrderSnapshot(array $plans): array
    {
        return array_map(
            static fn (ServiceOrderOperationPlan $plan): array => [
                'id' => (int) $plan->getId(),
                'sequence_number' => $plan->getSequenceNumber(),
                'operation_code' => $plan->getOperationSnapshot()['code'] ?? $plan->getOperation()->getCode(),
            ],
            $plans,
        );
    }

    /** @param list<ServiceOrderOperationPlan> $plans */
    private function findPlan(array $plans, int $id): ?ServiceOrderOperationPlan
    {
        foreach ($plans as $plan) {
            if ($plan->getId() === $id) {
                return $plan;
            }
        }

        return null;
    }

    /** @return array<string, int|string|null> */
    private function planningSnapshot(ServiceOrder $serviceOrder): array
    {
        return [
            'folio' => $serviceOrder->getFolio(),
            'status' => $serviceOrder->getStatus()->value,
            'commitment_date' => $serviceOrder->getCommitmentDate()?->format('Y-m-d'),
            'planned_at' => $serviceOrder->getPlannedAt()?->format(\DATE_ATOM),
            'planned_by_user_id' => $serviceOrder->getPlannedBy()?->getId(),
        ];
    }

    private function copyItem(QuotationItem $quotationItem): ServiceOrderItem
    {
        return (new ServiceOrderItem())
            ->setSourceQuotationItem($quotationItem)
            ->setCommercialItem($quotationItem->getCommercialItem())
            ->setLineNumber($quotationItem->getLineNumber())
            ->setQuantity($quotationItem->getQuantity())
            ->setUnitPrice($quotationItem->getUnitPrice())
            ->setLineSubtotal($quotationItem->getLineSubtotal())
            ->setCommercialItemSnapshot($quotationItem->getCommercialItemSnapshot())
            ->setPriceRuleSnapshot($quotationItem->getPriceRuleSnapshot())
            ->setSpecificationsSnapshot($quotationItem->getSpecificationsSnapshot())
            ->setSpecificationSchemaVersion($quotationItem->getSpecificationSchemaVersion());
    }

    /** @return array<string, int|string|null> */
    private function quotationSnapshot(Quotation $quotation): array
    {
        return [
            'quotation_id' => $quotation->getId(),
            'folio' => $quotation->getFolio(),
            'revision_number' => $quotation->getRevisionNumber(),
            'issued_at' => $quotation->getIssuedAt()?->format(\DATE_ATOM),
            'accepted_at' => $quotation->getDecisionAt()?->format(\DATE_ATOM),
            'acceptance_channel' => $quotation->getDecisionChannel()?->value,
            'acceptance_contact' => $quotation->getDecisionContact(),
            'acceptance_notes' => $quotation->getDecisionNotes(),
            'acceptance_evidence_reference' => $quotation->getDecisionEvidenceReference(),
        ];
    }

    /** @return array<string, int|string|null> */
    private function auditSnapshot(ServiceOrder $serviceOrder): array
    {
        return [
            'folio' => $serviceOrder->getFolio(),
            'status' => $serviceOrder->getStatus()->value,
            'source_quotation_id' => $serviceOrder->getSourceQuotation()->getId(),
            'source_quotation_folio' => $serviceOrder->getSourceQuotationFolio(),
            'client_name' => $serviceOrder->getClientSnapshot()['business_name'] ?? null,
            'total' => $serviceOrder->getTotal(),
            'currency' => $serviceOrder->getCurrency(),
            'commitment_date' => $serviceOrder->getCommitmentDate()?->format('Y-m-d'),
            'items' => $serviceOrder->getItems()->count(),
        ];
    }
}
