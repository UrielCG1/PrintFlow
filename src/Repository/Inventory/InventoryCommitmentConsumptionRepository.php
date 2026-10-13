<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryCommitmentConsumption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryCommitmentConsumption> */
final class InventoryCommitmentConsumptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryCommitmentConsumption::class);
    }

    public function allocatedQuantityForCommitment(InventoryCommitment $commitment): string
    {
        return (string) $this->createQueryBuilder('consumption')
            ->select('COALESCE(SUM(consumption.allocatedQuantity), 0)')
            ->andWhere('consumption.commitment = :commitment')
            ->setParameter('commitment', $commitment)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
