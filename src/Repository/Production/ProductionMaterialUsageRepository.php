<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionMaterialUsage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductionMaterialUsage> */
final class ProductionMaterialUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductionMaterialUsage::class);
    }

    /** @return list<ProductionMaterialUsage> */
    public function findForPlan(ServiceOrderOperationPlan $plan): array
    {
        /** @var list<ProductionMaterialUsage> $usages */
        $usages = $this->createQueryBuilder('usage')
            ->andWhere('usage.operationPlan = :plan')
            ->setParameter('plan', $plan)
            ->orderBy('usage.measuredAt', 'DESC')
            ->addOrderBy('usage.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $usages;
    }

    /** @return list<ProductionMaterialUsage> */
    public function findPlannedForPlan(ServiceOrderOperationPlan $plan): array
    {
        /** @var list<ProductionMaterialUsage> $usages */
        $usages = $this->createQueryBuilder('usage')
            ->andWhere('usage.operationPlan = :plan')
            ->andWhere('usage.operationExecution IS NULL')
            ->setParameter('plan', $plan)
            ->orderBy('usage.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $usages;
    }

    /** @return list<ProductionMaterialUsage> */
    public function findActualForExecution(\App\Entity\Production\ProductionOperationExecution $execution): array
    {
        /** @var list<ProductionMaterialUsage> $usages */
        $usages = $this->createQueryBuilder('usage')
            ->andWhere('usage.operationExecution = :execution')
            ->andWhere('usage.actualQuantity IS NOT NULL')
            ->setParameter('execution', $execution)
            ->orderBy('usage.measuredAt', 'ASC')
            ->addOrderBy('usage.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $usages;
    }
}
