<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Materials\MaterialVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MaterialVariantCost> */
final class MaterialVariantCostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaterialVariantCost::class);
    }

    public function findForUpdate(MaterialVariant $variant): ?MaterialVariantCost
    {
        return $this->createQueryBuilder('balance')
            ->andWhere('balance.variant = :variant')
            ->setParameter('variant', $variant)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
