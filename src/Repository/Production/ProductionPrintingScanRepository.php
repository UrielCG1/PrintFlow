<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\ProductionPrintingScan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductionPrintingScan> */
final class ProductionPrintingScanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductionPrintingScan::class);
    }

    public function findCurrentForPlanForUpdate(ServiceOrderOperationPlan $plan): ?ProductionPrintingScan
    {
        /** @var ProductionPrintingScan|null $scan */
        $scan = $this->createQueryBuilder('scan')
            ->andWhere('scan.plan = :plan')
            ->andWhere('scan.currentMarker = 1')
            ->setParameter('plan', $plan)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $scan;
    }

    public function findCurrentForPlan(ServiceOrderOperationPlan $plan): ?ProductionPrintingScan
    {
        return $this->findOneBy(['plan' => $plan, 'currentMarker' => 1]);
    }
}
