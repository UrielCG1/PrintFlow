<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Inventory\InventoryStockUnitOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryStockUnitOperation> */
final class InventoryStockUnitOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryStockUnitOperation::class);
    }

    /** @return list<InventoryStockUnitOperation> */
    public function findRecent(int $limit = 50): array
    {
        /** @var list<InventoryStockUnitOperation> $operations */
        $operations = $this->createQueryBuilder('operation')
            ->innerJoin('operation.stockUnit', 'stockUnit')
            ->innerJoin('operation.variant', 'variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->innerJoin('operation.responsibleUser', 'responsibleUser')
            ->addSelect('stockUnit', 'variant', 'material', 'unit', 'responsibleUser')
            ->orderBy('operation.occurredAt', 'DESC')
            ->addOrderBy('operation.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();

        return $operations;
    }

    /** @return list<InventoryStockUnitOperation> */
    public function findForStockUnit(InventoryStockUnit $stockUnit, int $limit = 50): array
    {
        /** @var list<InventoryStockUnitOperation> $operations */
        $operations = $this->createQueryBuilder('operation')
            ->innerJoin('operation.responsibleUser', 'responsibleUser')
            ->addSelect('responsibleUser')
            ->andWhere('operation.stockUnit = :stockUnit')
            ->setParameter('stockUnit', $stockUnit)
            ->orderBy('operation.occurredAt', 'DESC')
            ->addOrderBy('operation.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();

        return $operations;
    }
}
