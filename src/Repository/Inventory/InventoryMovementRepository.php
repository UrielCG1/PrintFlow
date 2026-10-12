<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryMovement;
use App\Entity\Materials\MaterialVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryMovement> */
final class InventoryMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryMovement::class);
    }

    public function hasAnyForVariant(MaterialVariant $variant): bool
    {
        return $this->createQueryBuilder('movement')
            ->select('movement.id')
            ->andWhere('movement.variant = :variant')
            ->setParameter('variant', $variant)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }
}
