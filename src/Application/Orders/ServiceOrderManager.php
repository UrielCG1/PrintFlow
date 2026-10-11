<?php

declare(strict_types=1);

namespace App\Application\Orders;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use App\Entity\Equipment\Equipment;
use App\Entity\Operations\Operation;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Quotations\Quotation;
use App\Entity\Quotations\QuotationItem;
use App\Entity\Users\User;
use App\Enum\Quotations\QuotationStatus;
use App\Enum\Orders\ProductionEstimateKind;
use App\Repository\Orders\ServiceOrderOperationPlanRepository;
use App\Repository\Orders\ServiceOrderRepository;
use App\Application\Production\ProductionScheduleLock;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderFolioGenerator;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class ServiceOrderManager
{
    private const PRODUCTION_ROUTE_REVIEW_ISSUE = 'Las medidas de producción se corrigieron; revisa la ruta y confírmala antes de planificar.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceOrderRepository $serviceOrderRepository,
        private readonly ServiceOrderOperationPlanRepository $operationPlanRepository,
        private readonly ServiceOrderFolioGenerator $folioGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly ProductionRouteApplicator $productionRouteApplicator,
        private readonly ProductionScheduleLock $scheduleLock,
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
                    $item = $this->copyItem($quotationItem);
                    $serviceOrder->addItem($item);
                    $this->productionRouteApplicator->apply($item, $actor);
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
        $scheduleLockAcquired = $this->scheduleLock->acquire();
        try {
            $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $data, $actor): void {
                $this->lockServiceOrder($serviceOrder);
                $commitmentDate = $this->normalizeCommitmentDate($data->commitmentDate);
                $oldValues = $this->planningSnapshot($serviceOrder);
                if ($serviceOrder->getCommitmentDate()?->format('Y-m-d') === $commitmentDate?->format('Y-m-d')) {
                    return;
                }

                $providedReason = trim($data->reason);
                $reason = $serviceOrder->getCommitmentDate() !== null || $providedReason !== ''
                    ? $this->validateChangeReason($providedReason)
                    : null;
                $serviceOrder
                    ->setCommitmentDate($commitmentDate)
                    ->setCommitmentReason($reason);
                $newValues = $this->planningSnapshot($serviceOrder);

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
        } finally {
            $this->scheduleLock->release($scheduleLockAcquired);
        }
    }

    /** @return list<ServiceOrderItem> Newly visible order rows, including the retained first child. */
    public function splitItem(
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        string $quantityList,
        string $reason,
        User $actor,
    ): array {
        return $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $item, $quantityList, $reason, $actor): array {
            $this->lockServiceOrder($serviceOrder);
            $this->assertPendingPlanning($serviceOrder);
            $this->lockAndAssertOrderItem($serviceOrder, $item);

            $reason = $this->validateChangeReason($reason);
            $quantities = $this->parseSplitQuantities($quantityList);
            if ($item->getSplitPosition() !== null || $item->getSplitParentItem() !== null) {
                throw new \DomainException('Una partida dividida no se puede volver a dividir en esta fase.');
            }
            foreach ($serviceOrder->getItems() as $candidate) {
                if ($candidate->getSplitParentItem() === $item) {
                    throw new \DomainException('Esta partida ya fue dividida.');
                }
            }

            $quantityTotal = BigDecimal::of('0');
            foreach ($quantities as $quantity) {
                $quantityTotal = $quantityTotal->plus($quantity);
            }
            if ($quantityTotal->compareTo($item->getQuantity()) !== 0) {
                throw new \DomainException('La suma de cantidades debe coincidir exactamente con la cantidad contratada.');
            }

            $activePlans = $this->operationPlanRepository->findActiveForItemForUpdate($item);
            foreach ($activePlans as $activePlan) {
                if ($activePlan->getEstimatedMinutes() !== null || $activePlan->hasActiveReservations()) {
                    throw new \DomainException('Retira las estimaciones y reservas antes de dividir esta partida.');
                }
            }

            $oldValues = $this->splitSnapshot($item);
            $subtotals = $this->allocateSplitSubtotals($item->getLineSubtotal(), $quantities);
            $maxLineNumber = max(array_map(
                static fn (ServiceOrderItem $orderItem): int => $orderItem->getLineNumber(),
                $serviceOrder->getItems()->toArray(),
            ));

            $item
                ->setSplitPosition(1)
                ->setQuantity($quantities[0])
                ->setLineSubtotal($subtotals[0]);
            $rows = [$item];

            foreach (array_slice($quantities, 1, null, true) as $position => $quantity) {
                $child = (new ServiceOrderItem())
                    ->setCommercialItem($item->getCommercialItem())
                    ->setLineNumber(++$maxLineNumber)
                    ->setQuantity($quantity)
                    ->setUnitPrice($item->getUnitPrice())
                    ->setLineSubtotal($subtotals[$position])
                    ->setCommercialItemSnapshot($item->getCommercialItemSnapshot())
                    ->setPriceRuleSnapshot($item->getPriceRuleSnapshot())
                    ->setSpecificationsSnapshot($item->getSpecificationsSnapshot())
                    ->setProductionSpecificationsSnapshot($item->getProductionSpecificationsSnapshot())
                    ->setSpecificationSchemaVersion($item->getSpecificationSchemaVersion())
                    ->setRoute($item->getRouteVersion(), $item->getRouteSnapshot())
                    ->setPlanningIssue($item->getPlanningIssue())
                    ->setPriority($item->getPriority())
                    ->setSplitParentItem($item)
                    ->setSplitPosition($position + 1);
                $serviceOrder->addItem($child);

                foreach ($activePlans as $activePlan) {
                    $plan = (new ServiceOrderOperationPlan())
                        ->setServiceOrderItem($child)
                        ->setOperation($activePlan->getOperation())
                        ->setEquipment($activePlan->getEquipment(), $activePlan->getEquipmentSnapshot())
                        ->setCreatedBy($actor)
                        ->setSequenceNumber($activePlan->getSequenceNumber())
                        ->setOperationSnapshot($activePlan->getOperationSnapshot())
                        ->setRouteStepSnapshot($activePlan->getRouteStepSnapshot())
                        ->setConditionApplied($activePlan->getConditionApplied())
                        ->setBlockedReason($activePlan->getBlockedReason());
                    $child->addOperationPlan($plan);
                }
                $rows[] = $child;
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'service_order.item_split',
                entityType: 'service_order_item',
                entityId: $item->getId(),
                oldValues: $oldValues,
                newValues: [
                    'reason' => $reason,
                    'rows' => array_map(static fn (ServiceOrderItem $row): array => [
                        'line_number' => $row->getDisplayLineNumber(),
                        'quantity' => $row->getQuantity(),
                        'line_subtotal' => $row->getLineSubtotal(),
                    ], $rows),
                    'order_total_unchanged' => $serviceOrder->getTotal(),
                ],
            );
            $this->entityManager->flush();

            return $rows;
        });
    }

    public function correctProductionSpecifications(
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        ?string $finishedWidthCm,
        ?string $finishedHeightCm,
        string $reason,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $item, $finishedWidthCm, $finishedHeightCm, $reason, $actor): void {
            $this->lockServiceOrder($serviceOrder);
            $this->assertPendingPlanning($serviceOrder);
            $this->lockAndAssertOrderItem($serviceOrder, $item);

            $reason = $this->validateChangeReason($reason);
            $oldSpecifications = $item->getProductionSpecificationsSnapshot();
            $plansBeforeCorrection = $this->operationPlanRepository->findActiveForItemForUpdate($item);
            $estimatesBeforeCorrection = [];
            foreach ($plansBeforeCorrection as $plan) {
                if ($plan->hasActiveReservations()) {
                    throw new \DomainException('No puedes corregir medidas después de reservar una operación.');
                }
                if ($plan->getEstimatedMinutes() !== null) {
                    $estimatesBeforeCorrection[] = [
                        'plan_id' => $plan->getId(),
                        'kind' => $plan->getEstimateKind()?->value,
                        'load_value' => $plan->getEstimatedLoadValue(),
                        'load_unit' => $plan->getEstimatedLoadUnit()?->value,
                        'minutes' => $plan->getEstimatedMinutes(),
                        'snapshot' => $plan->getEstimateSnapshot(),
                    ];
                }
            }
            $updates = [];
            foreach (['finished_width_cm' => $finishedWidthCm, 'finished_height_cm' => $finishedHeightCm] as $key => $value) {
                if (trim((string) $value) !== '') {
                    $normalized = $this->normalizeProductionDimension((string) $value);
                    $current = $this->snapshotDimension($oldSpecifications, $key);
                    if ($current === null || BigDecimal::of($current)->compareTo($normalized) !== 0) {
                        $updates[$key] = $normalized;
                    }
                }
            }
            if ($updates === []) {
                throw new \DomainException('Captura al menos una medida distinta de la actual.');
            }

            $newSpecifications = $oldSpecifications;
            foreach ($updates as $key => $value) {
                $newSpecifications[$key] = $value;
                $characteristicCode = strtoupper($key);
                if (isset($newSpecifications['values'][$characteristicCode]) && is_array($newSpecifications['values'][$characteristicCode])) {
                    $newSpecifications['values'][$characteristicCode]['submitted_value'] = $value;
                }
                if (isset($newSpecifications['large_format']['values'][$key])) {
                    $newSpecifications['large_format']['values'][$key] = $value;
                }
            }

            $width = $this->snapshotDimension($newSpecifications, 'finished_width_cm');
            $height = $this->snapshotDimension($newSpecifications, 'finished_height_cm');
            if ($width !== null && $height !== null) {
                $area = BigDecimal::of($width)
                    ->multipliedBy($height)
                    ->dividedBy('10000', 6, RoundingMode::HalfUp)
                    ->__toString();
                if (isset($newSpecifications['large_format']['calculated']) && is_array($newSpecifications['large_format']['calculated'])) {
                    $newSpecifications['large_format']['calculated']['area_m2'] = $area;
                }
                if (isset($newSpecifications['calculated']) && is_array($newSpecifications['calculated']) && array_key_exists('area_m2', $newSpecifications['calculated'])) {
                    $newSpecifications['calculated']['area_m2'] = $area;
                }
                $newSpecifications['summary'] = sprintf('Ancho %s cm x alto %s cm', $width, $height);
            }

            if ($newSpecifications === $oldSpecifications) {
                throw new \DomainException('Las medidas capturadas coinciden con las actuales.');
            }

            $newSpecifications['_production_adjustment'] = [
                'type' => 'technical_correction',
                'corrected_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
                'corrected_by_user_id' => $actor->getId(),
            ];

            $item->setProductionSpecificationsSnapshot($newSpecifications);
            foreach ($plansBeforeCorrection as $plan) {
                $plan->clearEstimate();
            }
            $item->setPlanningIssue(self::PRODUCTION_ROUTE_REVIEW_ISSUE);
            $this->auditLogger->record(
                actor: $actor,
                action: 'service_order.item_production_specifications_corrected',
                entityType: 'service_order_item',
                entityId: $item->getId(),
                oldValues: [
                    'production_specifications' => $oldSpecifications,
                    'operation_estimates_invalidated' => $estimatesBeforeCorrection,
                ],
                newValues: [
                    'production_specifications' => $newSpecifications,
                    'reason' => $reason,
                    'quotation_specifications_unchanged' => true,
                    'commercial_amounts_unchanged' => true,
                    'estimate_recalculation_required' => $estimatesBeforeCorrection !== [],
                ],
            );
            $this->entityManager->flush();
        });
    }

    public function confirmProductionRouteReview(
        ServiceOrder $serviceOrder,
        ServiceOrderItem $item,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($serviceOrder, $item, $actor): void {
            $this->lockServiceOrder($serviceOrder);
            $this->assertPendingPlanning($serviceOrder);
            $this->lockAndAssertOrderItem($serviceOrder, $item);

            if ($item->getPlanningIssue() !== self::PRODUCTION_ROUTE_REVIEW_ISSUE) {
                throw new \DomainException('Esta partida no tiene una revisión de ruta pendiente.');
            }
            $activePlans = $this->operationPlanRepository->findActiveForItemForUpdate($item);
            if ($item->getRouteVersion() === null || $activePlans === []) {
                throw new \DomainException('Agrega una ruta válida antes de confirmar su revisión.');
            }

            $item->setPlanningIssue(null);
            $this->auditLogger->record(
                actor: $actor,
                action: 'service_order.item_production_route_reviewed',
                entityType: 'service_order_item',
                entityId: $item->getId(),
                newValues: [
                    'line_number' => $item->getDisplayLineNumber(),
                    'route_version_id' => $item->getRouteVersion()->getId(),
                    'active_operation_count' => count($activePlans),
                ],
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
                $serviceOrderItem->setPlanningIssue(null);

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
            $serviceOrderItem->setPlanningIssue(null);

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
            if ($plan->hasActiveReservations()) {
                throw new \DomainException('No puedes cambiar el equipo después de crear reservas.');
            }

            $operation = $this->lockOperation($plan->getOperation());
            $this->assertOperationAvailableForPlanning($operation);

            $equipment = $data->equipment;
            if ($equipment !== null) {
                $this->entityManager->lock($equipment, LockMode::PESSIMISTIC_WRITE);
                $this->assertEquipmentAvailableForOperation($equipment, $operation);
            }

            $oldValues = $this->operationPlanSnapshot($plan);
            $oldEquipmentId = $plan->getEquipment()?->getId();
            $oldEstimateKind = $plan->getEstimateKind();
            $plan->setEquipment($equipment, $equipment !== null ? $this->equipmentSnapshot($equipment) : null);
            if ($plan->getEquipment()?->getId() !== $oldEquipmentId && $oldEstimateKind === ProductionEstimateKind::CALCULATED) {
                $plan->clearEstimate();
            }
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
            if ($plan->getEstimatedMinutes() !== null || $plan->hasActiveReservations()) {
                throw new \DomainException('No puedes retirar una operación con estimación o reserva; primero resuelve su planificación.');
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
            foreach ($plans as $plan) {
                if ($plan->hasActiveReservations()) {
                    throw new \DomainException('No puedes cambiar la secuencia después de reservar una máquina.');
                }
            }
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
                if ($serviceOrderItem->getPlanningIssue() !== null) {
                    throw new \DomainException(sprintf(
                        'La partida %d requiere revisión: %s',
                        $serviceOrderItem->getLineNumber(),
                        $serviceOrderItem->getPlanningIssue(),
                    ));
                }
                $plans = $this->operationPlanRepository->findActiveForItemForUpdate($serviceOrderItem);
                if ($plans === []) {
                    throw new \DomainException(sprintf('La partida %d debe tener al menos una operación planificada.', $serviceOrderItem->getLineNumber()));
                }

                foreach ($plans as $plan) {
                    if ($plan->getBlockedReason() !== null) {
                        throw new \DomainException(sprintf(
                            'La operación %s de la partida %d está bloqueada: %s',
                            $plan->getOperationSnapshot()['name'] ?? $plan->getOperation()->getName(),
                            $serviceOrderItem->getLineNumber(),
                            $plan->getBlockedReason(),
                        ));
                    }
                    $operation = $this->lockOperation($plan->getOperation());
                    $this->assertOperationAvailableForPlanning($operation);
                    $equipment = $plan->getEquipment();
                    if ($equipment === null) {
                        throw new \DomainException(sprintf('La operación %s de la partida %d no tiene máquina asignada.', $plan->getOperationSnapshot()['name'] ?? $operation->getName(), $serviceOrderItem->getLineNumber()));
                    }
                    $this->entityManager->lock($equipment, LockMode::PESSIMISTIC_WRITE);
                    $this->assertEquipmentAvailableForOperation($equipment, $operation);
                    if ($plan->getEstimatedMinutes() === null || !$plan->hasActiveReservations()) {
                        throw new \DomainException(sprintf(
                            'La operación %s de la partida %d necesita estimación y reserva de máquina antes de marcar la orden como planificada.',
                            $plan->getOperationSnapshot()['name'] ?? $operation->getName(),
                            $serviceOrderItem->getLineNumber(),
                        ));
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

    /** @return list<string> */
    private function parseSplitQuantities(string $quantityList): array
    {
        $entries = preg_split('/[;\r\n]+/', trim($quantityList), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($entries) < 2 || count($entries) > 20) {
            throw new \DomainException('Captura entre 2 y 20 cantidades, una por renglón.');
        }

        $quantities = [];
        foreach ($entries as $entry) {
            $value = str_replace(',', '.', trim($entry));
            if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?$/D', $value) !== 1) {
                throw new \DomainException('Cada cantidad debe ser positiva y usar hasta 6 decimales.');
            }
            $quantity = BigDecimal::of($value);
            if ($quantity->compareTo('0') <= 0) {
                throw new \DomainException('Cada cantidad debe ser mayor que cero.');
            }
            $quantities[] = $quantity->toScale(6)->__toString();
        }

        return $quantities;
    }

    /** @param list<string> $quantities @return list<string> */
    private function allocateSplitSubtotals(string $lineSubtotal, array $quantities): array
    {
        $totalQuantity = array_reduce(
            $quantities,
            static fn (BigDecimal $sum, string $quantity): BigDecimal => $sum->plus($quantity),
            BigDecimal::of('0'),
        );
        $total = BigDecimal::of($lineSubtotal)->toScale(2);
        $allocated = [];
        $allocatedTotal = BigDecimal::of('0');

        foreach ($quantities as $quantity) {
            $amount = $total
                ->multipliedBy($quantity)
                ->dividedBy($totalQuantity, 2, RoundingMode::HalfUp)
                ->toScale(2, RoundingMode::HalfUp);
            $allocated[] = $amount;
            $allocatedTotal = $allocatedTotal->plus($amount);
        }

        $residual = $total->minus($allocatedTotal);
        if ($residual->compareTo('0') >= 0) {
            $last = count($allocated) - 1;
            $allocated[$last] = $allocated[$last]->plus($residual);
        } else {
            // Independent rounding can exceed the source by a cent per row.
            // Remove that excess from the last positive allocations so no child
            // receives a negative amount and the original subtotal is exact.
            $excess = $residual->abs();
            for ($index = count($allocated) - 1; $index >= 0 && $excess->compareTo('0') > 0; --$index) {
                $reduction = $allocated[$index]->compareTo($excess) < 0 ? $allocated[$index] : $excess;
                $allocated[$index] = $allocated[$index]->minus($reduction);
                $excess = $excess->minus($reduction);
            }
            if ($excess->compareTo('0') > 0) {
                throw new \DomainException('No fue posible distribuir el importe original entre las partidas hijas.');
            }
        }

        return array_map(static fn (BigDecimal $amount): string => $amount->toScale(2)->__toString(), $allocated);
    }

    private function validateChangeReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new \DomainException('Indica el motivo del cambio (de 5 a 500 caracteres).');
        }

        return $reason;
    }

    private function normalizeProductionDimension(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
        if (preg_match('/^(?:0|[1-9]\d{0,5})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \DomainException('Las medidas deben ser números positivos en centímetros, con hasta 6 decimales.');
        }

        $dimension = BigDecimal::of($value);
        if ($dimension->compareTo('0') <= 0 || $dimension->compareTo('100000') > 0) {
            throw new \DomainException('Cada medida debe ser mayor que cero y no superar 100,000 cm.');
        }

        return $dimension->toScale(6)->__toString();
    }

    /** @param array<string, mixed> $specifications */
    private function snapshotDimension(array $specifications, string $key): ?string
    {
        $code = strtoupper($key);
        $candidate = $specifications['large_format']['values'][$key]
            ?? $specifications['values'][$code]['submitted_value']
            ?? $specifications[$key]
            ?? null;

        return is_scalar($candidate) && trim((string) $candidate) !== ''
            ? $this->normalizeProductionDimension((string) $candidate)
            : null;
    }

    /** @return array<string, int|string|null> */
    private function splitSnapshot(ServiceOrderItem $item): array
    {
        return [
            'line_number' => $item->getLineNumber(),
            'quantity' => $item->getQuantity(),
            'unit_price' => $item->getUnitPrice(),
            'line_subtotal' => $item->getLineSubtotal(),
            'source_quotation_item_id' => $item->getSourceQuotationItem()?->getId(),
        ];
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
            'estimate_kind' => $plan->getEstimateKind()?->value,
            'estimated_load_value' => $plan->getEstimatedLoadValue(),
            'estimated_load_unit' => $plan->getEstimatedLoadUnit()?->value,
            'estimated_minutes' => $plan->getEstimatedMinutes(),
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
            'commitment_reason' => $serviceOrder->getCommitmentReason(),
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
            ->setProductionSpecificationsSnapshot($quotationItem->getSpecificationsSnapshot())
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
            'pricing_engine_version' => $quotation->getPricingEngineVersion(),
            'pricing_calculation_version' => $quotation->getPricingCalculationVersion(),
            'pricing_hash' => $quotation->getPricingHash(),
            'volume_hash' => $quotation->getVolumeHash(),
            'discount_breakdown' => $quotation->getDiscountBreakdown(),
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
