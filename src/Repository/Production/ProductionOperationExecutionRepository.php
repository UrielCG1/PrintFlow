<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Production\ProductionOperationExecution;
use App\Enum\Production\ProductionOperationExecutionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductionOperationExecution> */
final class ProductionOperationExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductionOperationExecution::class);
    }

    /** Indica si una etapa posterior a Diseño ya dejó evidencia de ejecución. */
    public function hasStartedAfterDesignForItem(ServiceOrderItem $item): bool
    {
        return (int) $this->createQueryBuilder('execution')
            ->select('COUNT(execution.id)')
            ->innerJoin('execution.plan', 'plan')
            ->andWhere('plan.serviceOrderItem = :item')
            ->andWhere('plan.sequenceNumber > :designSequence')
            ->andWhere('execution.status <> :cancelled')
            ->setParameter('item', $item)
            ->setParameter('designSequence', 1)
            ->setParameter('cancelled', ProductionOperationExecutionStatus::CANCELLED)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findOpenForPlanForUpdate(\App\Entity\Orders\ServiceOrderOperationPlan $plan): ?ProductionOperationExecution
    {
        /** @var ProductionOperationExecution|null $execution */
        $execution = $this->createQueryBuilder('execution')
            ->andWhere('execution.plan = :plan')
            ->andWhere('execution.openMarker = 1')
            ->setParameter('plan', $plan)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $execution;
    }
}
