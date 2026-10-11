<?php

declare(strict_types=1);

namespace App\Repository\Orders;

use App\Entity\Orders\ServiceOrderTraceabilityEvent;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionOperationExecution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceOrderTraceabilityEvent> */
final class ServiceOrderTraceabilityEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceOrderTraceabilityEvent::class);
    }

    public function findOneByCommandId(string $commandId): ?ServiceOrderTraceabilityEvent
    {
        return $this->findOneBy(['commandId' => $commandId]);
    }

    public function findLatestQualityRejectionForPlan(ServiceOrderOperationPlan $plan): ?ServiceOrderTraceabilityEvent
    {
        /** @var ServiceOrderTraceabilityEvent|null $event */
        $event = $this->createQueryBuilder('event')
            ->andWhere('event.operationPlan = :plan')
            ->andWhere('event.eventType = :eventType')
            ->setParameter('plan', $plan)
            ->setParameter('eventType', 'quality.operation_rejected')
            ->orderBy('event.occurredAt', 'DESC')
            ->addOrderBy('event.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $event;
    }

    public function hasQualityRejectionForExecution(ProductionOperationExecution $execution): bool
    {
        return $this->count([
            'operationExecution' => $execution,
            'eventType' => 'quality.operation_rejected',
        ]) > 0;
    }

    /** @return list<ServiceOrderTraceabilityEvent> */
    public function findRecentForServiceOrder(ServiceOrder $serviceOrder, int $limit = 100): array
    {
        /** @var list<ServiceOrderTraceabilityEvent> $events */
        $events = $this->createQueryBuilder('event')
            ->andWhere('event.serviceOrder = :serviceOrder')
            ->setParameter('serviceOrder', $serviceOrder)
            ->orderBy('event.occurredAt', 'DESC')
            ->addOrderBy('event.id', 'DESC')
            ->setMaxResults(max(1, min(250, $limit)))
            ->getQuery()
            ->getResult();

        return $events;
    }
}
