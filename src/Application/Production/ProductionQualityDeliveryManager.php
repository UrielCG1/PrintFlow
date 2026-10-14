<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Inventory\InventoryOrderCommitmentManager;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Orders\ServiceOrderTraceabilityEvent;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Production\ProductionMaterialUsage;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionOrderStatus;
use App\Enum\Orders\ServiceOrderDeliveryStatus;
use App\Enum\Orders\ServiceOrderFulfillmentMethod;
use App\Enum\Production\ProductionOperationExecutionStatus;
use App\Enum\Production\CalculationMethod;
use App\Repository\Orders\ServiceOrderTraceabilityEventRepository;
use App\Repository\Production\ProductionMaterialUsageRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Orders\ServiceOrderTraceabilityRecorder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Calidad y entrega son hitos separados, con evidencia append-only en la orden. */
final class ProductionQualityDeliveryManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceOrderTraceabilityEventRepository $eventRepository,
        private readonly ProductionMaterialUsageRepository $materialUsageRepository,
        private readonly InventoryOrderCommitmentManager $inventoryCommitmentManager,
        private readonly ServiceOrderTraceabilityRecorder $traceabilityRecorder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function releaseProduction(ServiceOrder $order, User $actor, ProductionExecutionCommand $command): void
    {
        $this->entityManager->wrapInTransaction(function () use ($order, $actor, $command): void {
            if ($this->replayed($order, $command, 'quality.order_released')) {
                return;
            }
            $this->assertCurrentVersion($order, $command);
            if ($order->getProductionStatus() !== ProductionOrderStatus::WAITING_QUALITY) {
                throw new \DomainException('La orden debe estar pendiente de revisión de Calidad.');
            }
            $this->assertAllOperationsComplete($order);

            $previous = $order->traceabilityStateSnapshot();
            $order->markProductionCompleted();
            $deliveryReady = null;
            if ($order->isDeliveryPrepared()) {
                $deliveryReady = $this->markDeliveryReadyForConfiguredMethod($order);
            }
            $order->touchTraceability();
            $new = $order->traceabilityStateSnapshot();
            $this->record($order, 'quality.order_released', $actor, $previous, $new, $command, null, [
                'review_note' => $command->note,
                'delivery_prepared' => $deliveryReady !== null,
            ]);
            if ($deliveryReady !== null) {
                $this->traceabilityRecorder->record(
                    $order,
                    'delivery.ready',
                    $actor,
                    $previous,
                    $new,
                    null,
                    null,
                    null,
                    null,
                    ['status' => $deliveryReady->value, 'trigger' => 'quality_release'],
                    $command->commandId,
                );
            }
            $this->auditLogger->record($actor, 'quality.order_released', 'service_order', $order->getId(), $previous, $new);
            $this->entityManager->flush();
        });
    }

    public function returnOperationForRework(
        ServiceOrder $order,
        ServiceOrderItem $item,
        ServiceOrderOperationPlan $plan,
        ProductionOperationExecution $execution,
        User $actor,
        ProductionExecutionCommand $command,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($order, $item, $plan, $execution, $actor, $command): void {
            if ($this->replayed($order, $command, 'quality.operation_rejected')) {
                return;
            }
            $this->assertCurrentVersion($order, $command);
            if ($order->getProductionStatus() !== ProductionOrderStatus::WAITING_QUALITY) {
                throw new \DomainException('Solo una orden pendiente de Calidad puede devolverse a retrabajo.');
            }
            if ($item->getServiceOrder() !== $order || $plan->getServiceOrderItem() !== $item || $execution->getPlan() !== $plan) {
                throw new \DomainException('La partida, operación e intento no pertenecen a esta orden.');
            }
            $this->entityManager->lock($plan, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($execution, LockMode::PESSIMISTIC_WRITE);
            if (!$plan->isActive() || $plan->getLatestExecution() !== $execution
                || $execution->getStatus() !== ProductionOperationExecutionStatus::COMPLETED
                || $plan->hasOpenExecution()
            ) {
                throw new \DomainException('Calidad solo puede devolver el último intento completado de una operación activa.');
            }
            $reason = $command->note;
            if ($reason === null || mb_strlen($reason) < 5) {
                throw new \InvalidArgumentException('Describe la no conformidad con al menos 5 caracteres.');
            }

            $previous = $order->traceabilityStateSnapshot();
            $order->returnForQualityRework();
            $reworkMaterialRequirements = $this->createReworkMaterialRequirements($order, $plan, $execution, $actor);
            $order->touchTraceability();
            $new = $order->traceabilityStateSnapshot();
            $this->record($order, 'quality.operation_rejected', $actor, $previous, $new, $command, $reason, [
                'operation_code' => $plan->getOperationSnapshot()['code'] ?? $plan->getOperation()->getCode(),
                'attempt_number' => $execution->getAttemptNumber(),
                'next_area' => $plan->getOperation()->getOperationArea()->getName(),
                'rework_material_requirements' => $reworkMaterialRequirements,
            ], $item, $plan, $execution);
            $this->auditLogger->record($actor, 'quality.operation_rejected', 'production_operation_execution', $execution->getId(), $previous, $new);
            $this->entityManager->flush();
        });
    }

    public function setFulfillmentMethod(
        ServiceOrder $order,
        ServiceOrderFulfillmentMethod $method,
        User $actor,
        ProductionExecutionCommand $command,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($order, $method, $actor, $command): void {
            if ($this->replayed($order, $command, 'delivery.method_set')) {
                return;
            }
            $this->assertCurrentVersion($order, $command);
            if ($order->getProductionStatus() !== ProductionOrderStatus::PRODUCTION_COMPLETED
                || $order->getDeliveryStatus() !== ServiceOrderDeliveryStatus::NOT_READY
                || $method === ServiceOrderFulfillmentMethod::UNDEFINED
            ) {
                throw new \DomainException('Ventas puede definir recogida o envío después de liberar Producción y antes de preparar entrega.');
            }
            if ($method === ServiceOrderFulfillmentMethod::SHIPPING && empty($order->getDeliveryAddressSnapshot())) {
                throw new \DomainException('La cotización aceptada no tiene domicilio de entrega; solicita a Ventas completar el dato comercial.');
            }

            $previous = $order->traceabilityStateSnapshot();
            $order->setFulfillmentMethod($method)->setDeliveryPreparationIssue(null);
            $status = $this->markDeliveryReadyForConfiguredMethod($order);
            if ($status === null) {
                throw new \DomainException('La modalidad no pudo preparar la entrega de esta orden.');
            }
            $order->touchTraceability();
            $new = $order->traceabilityStateSnapshot();
            $this->record($order, 'delivery.method_set', $actor, $previous, $new, $command, $command->note, [
                'fulfillment_method' => $method->value,
                'delivery_status' => $status->value,
            ]);
            $this->traceabilityRecorder->record($order, 'delivery.ready', $actor, $previous, $new, null, null, null, null, [
                'status' => $status->value,
                'trigger' => 'sales_fulfillment_confirmation',
            ], $command->commandId);
            $this->auditLogger->record($actor, 'delivery.method_set', 'service_order', $order->getId(), $previous, $new);
            $this->entityManager->flush();
        });
    }

    public function markShipped(
        ServiceOrder $order,
        User $actor,
        ProductionExecutionCommand $command,
        string $carrier,
        ?string $trackingCode = null,
    ): void {
        $carrier = trim($carrier);
        $trackingCode = $trackingCode !== null ? trim($trackingCode) : null;
        if (mb_strlen($carrier) < 2 || mb_strlen($carrier) > 100) {
            throw new \InvalidArgumentException('Indica la paquetería o responsable del envío (2 a 100 caracteres).');
        }
        if ($trackingCode !== null && (mb_strlen($trackingCode) > 120 || $trackingCode === '')) {
            throw new \InvalidArgumentException('El número de guía no tiene un formato válido.');
        }

        $this->entityManager->wrapInTransaction(function () use ($order, $actor, $command, $carrier, $trackingCode): void {
            if ($this->replayed($order, $command, 'delivery.shipped')) {
                return;
            }
            $this->assertCurrentVersion($order, $command);
            if ($order->getFulfillmentMethod() !== ServiceOrderFulfillmentMethod::SHIPPING) {
                throw new \DomainException('Solo se registra envío para órdenes con modalidad a domicilio.');
            }
            $previous = $order->traceabilityStateSnapshot();
            $order->markShipped()->touchTraceability();
            $new = $order->traceabilityStateSnapshot();
            $context = ['carrier' => $carrier, 'tracking_code' => $trackingCode];
            $this->record($order, 'delivery.shipped', $actor, $previous, $new, $command, $command->note, $context);
            $this->auditLogger->record($actor, 'delivery.shipped', 'service_order', $order->getId(), $previous, $new);
            $this->entityManager->flush();
        });
    }

    public function confirmDelivered(
        ServiceOrder $order,
        User $actor,
        ProductionExecutionCommand $command,
        ?string $recipient = null,
    ): void {
        $recipient = $recipient !== null ? trim($recipient) : null;
        if ($recipient !== null && (mb_strlen($recipient) > 160 || $recipient === '')) {
            throw new \InvalidArgumentException('El nombre de quien recibió no tiene un formato válido.');
        }

        $this->entityManager->wrapInTransaction(function () use ($order, $actor, $command, $recipient): void {
            if ($this->replayed($order, $command, 'delivery.confirmed')) {
                return;
            }
            $this->assertCurrentVersion($order, $command);
            $previous = $order->traceabilityStateSnapshot();
            $order->markDelivered()->touchTraceability();
            $new = $order->traceabilityStateSnapshot();
            $this->record($order, 'delivery.confirmed', $actor, $previous, $new, $command, $command->note, [
                'recipient' => $recipient,
            ]);
            $this->auditLogger->record($actor, 'delivery.confirmed', 'service_order', $order->getId(), $previous, $new);
            $this->entityManager->flush();
        });
    }

    /**
     * A quality rejection does not make material already consumed available
     * again. It adds a fresh planned requirement for the next authorized
     * attempt, then reconciles only the difference against the order reserve.
     *
     * @return list<array{variant_id: int|null, material_code: string, quantity: string, origin_usage_id: int|null}>
     */
    private function createReworkMaterialRequirements(
        ServiceOrder $order,
        ServiceOrderOperationPlan $plan,
        ProductionOperationExecution $execution,
        User $actor,
    ): array {
        $requirements = [];
        foreach ($this->materialUsageRepository->findActualForExecution($execution) as $usage) {
            $variant = $usage->getVariant();
            $quantity = $usage->getActualQuantity();
            if ($variant === null || $quantity === null || $usage->getMovement() === null) {
                continue;
            }

            $reworkUsage = new ProductionMaterialUsage(
                $plan->getServiceOrderItem(),
                $variant,
                $variant->getConsumptionUnit(),
                $quantity,
                CalculationMethod::FIXED,
                1,
                [
                    'source' => 'quality-rework-demand',
                    'version' => 1,
                    'origin_execution_id' => $execution->getId(),
                    'origin_usage_id' => $usage->getId(),
                    'variant_code' => $variant->getCode(),
                ],
                $variant->getMaterial()->getName(),
                $variant->getConsumptionUnit()->getSymbol(),
            );
            $reworkUsage->assignPlanContext($plan);
            $this->entityManager->persist($reworkUsage);
            $requirements[] = [
                'variant_id' => $variant->getId(),
                'material_code' => $variant->getCode(),
                'quantity' => $quantity,
                'origin_usage_id' => $usage->getId(),
            ];
        }

        if ($requirements !== []) {
            // The collector reads persisted planned usages. This flush remains
            // inside the caller transaction, so a failed reserve rolls back
            // both the new requirement and its audit trail.
            $this->entityManager->flush();
            $this->inventoryCommitmentManager->commitForOrder($order, $actor);
        }

        return $requirements;
    }

    private function assertAllOperationsComplete(ServiceOrder $order): void
    {
        $hasOperations = false;
        foreach ($order->getItems() as $item) {
            foreach ($item->getActiveOperationPlans() as $plan) {
                $hasOperations = true;
                if ($plan->getBlockedReason() !== null || $plan->hasOpenExecution() || !$plan->hasCompletedLatestExecution()) {
                    throw new \DomainException('Todas las operaciones deben estar completadas y sin incidencias antes de liberar Producción.');
                }
            }
        }
        if (!$hasOperations) {
            throw new \DomainException('La orden no tiene una ruta operativa que Calidad pueda liberar.');
        }
    }

    private function markDeliveryReadyForConfiguredMethod(ServiceOrder $order): ?ServiceOrderDeliveryStatus
    {
        if (!$order->isDeliveryPrepared()) {
            return null;
        }
        return match ($order->getFulfillmentMethod()) {
            ServiceOrderFulfillmentMethod::PICKUP => $order->markReadyForPickup()->getDeliveryStatus(),
            ServiceOrderFulfillmentMethod::SHIPPING => $order->markReadyForShipment()->getDeliveryStatus(),
            ServiceOrderFulfillmentMethod::UNDEFINED => null,
        };
    }

    private function assertCurrentVersion(ServiceOrder $order, ProductionExecutionCommand $command): void
    {
        $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
        if ($order->getTraceabilityVersion() !== $command->expectedOrderVersion) {
            throw new \DomainException('La orden cambió desde que abriste esta pantalla. Actualízala antes de confirmar.');
        }
    }

    private function replayed(ServiceOrder $order, ProductionExecutionCommand $command, string $eventType): bool
    {
        $event = $this->eventRepository->findOneByCommandId($command->commandId);
        if (!$event instanceof ServiceOrderTraceabilityEvent) {
            return false;
        }
        if ($event->getEventType() !== $eventType || $event->getServiceOrder() !== $order) {
            throw new \DomainException('El identificador de comando ya se usó para otra acción u orden.');
        }

        return true;
    }

    /** @param array<string, mixed>|null $context */
    private function record(
        ServiceOrder $order,
        string $eventType,
        User $actor,
        array $previous,
        array $new,
        ProductionExecutionCommand $command,
        ?string $reason,
        ?array $context,
        ?ServiceOrderItem $item = null,
        ?ServiceOrderOperationPlan $plan = null,
        ?ProductionOperationExecution $execution = null,
    ): ServiceOrderTraceabilityEvent {
        return $this->traceabilityRecorder->record(
            $order,
            $eventType,
            $actor,
            $previous,
            $new,
            $item,
            $plan,
            $execution,
            $reason,
            $context,
            $command->commandId,
            $command->commandId,
        );
    }
}
