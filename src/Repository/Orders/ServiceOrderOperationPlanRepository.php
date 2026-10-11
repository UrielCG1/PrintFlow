<?php

declare(strict_types=1);

namespace App\Repository\Orders;

use App\Entity\Operations\Operation;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceOrderOperationPlan> */
final class ServiceOrderOperationPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceOrderOperationPlan::class);
    }

    /** @return list<ServiceOrderOperationPlan> */
    public function findActiveForServiceOrder(ServiceOrder $serviceOrder): array
    {
        /** @var list<ServiceOrderOperationPlan> $plans */
        $plans = $this->createQueryBuilder('plan')
            ->innerJoin('plan.serviceOrderItem', 'item')
            ->innerJoin('plan.operation', 'operation')
            ->innerJoin('operation.operationArea', 'area')
            ->leftJoin('plan.equipment', 'equipment')
            ->addSelect('item', 'operation', 'area', 'equipment')
            ->andWhere('item.serviceOrder = :serviceOrder')
            ->andWhere('plan.isActive = :active')
            ->setParameter('serviceOrder', $serviceOrder)
            ->setParameter('active', true)
            ->orderBy('item.lineNumber', 'ASC')
            ->addOrderBy('plan.sequenceNumber', 'ASC')
            ->addOrderBy('plan.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $plans;
    }

    /** @return list<ServiceOrderOperationPlan> */
    public function findActiveForItemForUpdate(ServiceOrderItem $serviceOrderItem): array
    {
        /** @var list<ServiceOrderOperationPlan> $plans */
        $plans = $this->createQueryBuilder('plan')
            ->innerJoin('plan.operation', 'operation')
            ->innerJoin('operation.operationArea', 'area')
            ->leftJoin('plan.equipment', 'equipment')
            ->addSelect('operation', 'area', 'equipment')
            ->andWhere('plan.serviceOrderItem = :serviceOrderItem')
            ->andWhere('plan.isActive = :active')
            ->setParameter('serviceOrderItem', $serviceOrderItem)
            ->setParameter('active', true)
            ->orderBy('plan.sequenceNumber', 'ASC')
            ->addOrderBy('plan.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $plans;
    }

    public function findOneByItemAndOperationForUpdate(
        ServiceOrderItem $serviceOrderItem,
        Operation $operation,
    ): ?ServiceOrderOperationPlan {
        /** @var ServiceOrderOperationPlan|null $plan */
        $plan = $this->createQueryBuilder('plan')
            ->andWhere('plan.serviceOrderItem = :serviceOrderItem')
            ->andWhere('plan.operation = :operation')
            ->setParameter('serviceOrderItem', $serviceOrderItem)
            ->setParameter('operation', $operation)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $plan;
    }

    public function findPreviousActivePlan(ServiceOrderOperationPlan $plan): ?ServiceOrderOperationPlan
    {
        /** @var ServiceOrderOperationPlan|null $previous */
        $previous = $this->createQueryBuilder('previous')
            ->andWhere('previous.serviceOrderItem = :item')
            ->andWhere('previous.isActive = true')
            ->andWhere('(previous.conditionApplied IS NULL OR previous.conditionApplied = true)')
            ->andWhere('previous.sequenceNumber < :sequence')
            ->setParameter('item', $plan->getServiceOrderItem())
            ->setParameter('sequence', $plan->getSequenceNumber())
            ->orderBy('previous.sequenceNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        return $previous;
    }
}
