<?php

declare(strict_types=1);

namespace App\Service\Orders;

use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Orders\ServiceOrderTraceabilityEvent;
use App\Entity\Production\ProductionOperationExecution;
use App\Entity\Users\User;
use Doctrine\ORM\EntityManagerInterface;

/** Persiste eventos operativos dentro de la transacción del caso de uso. */
final class ServiceOrderTraceabilityRecorder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed>|null $newState
     * @param array<string, mixed>|null $context
     */
    public function record(
        ServiceOrder $serviceOrder,
        string $eventType,
        ?User $actor,
        ?array $previousState,
        ?array $newState,
        ?ServiceOrderItem $serviceOrderItem = null,
        ?ServiceOrderOperationPlan $operationPlan = null,
        ?ProductionOperationExecution $operationExecution = null,
        ?string $reason = null,
        ?array $context = null,
        ?string $correlationId = null,
        ?string $commandId = null,
    ): ServiceOrderTraceabilityEvent {
        $event = new ServiceOrderTraceabilityEvent(
            $serviceOrder,
            $eventType,
            $actor,
            $previousState,
            $newState,
            $serviceOrderItem,
            $operationPlan,
            $operationExecution,
            $reason,
            $context,
            $correlationId,
            $commandId,
        );

        $this->entityManager->persist($event);

        return $event;
    }
}
