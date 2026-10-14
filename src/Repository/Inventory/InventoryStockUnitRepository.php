<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Application\Inventory\InventoryStockUnitPage;
use App\Entity\Inventory\InventoryStockUnit;
use App\Enum\Inventory\InventoryStockUnitStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryStockUnit> */
final class InventoryStockUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryStockUnit::class);
    }

    public function findOneByQrToken(string $qrToken): ?InventoryStockUnit
    {
        return $this->findOneBy(['qrToken' => strtolower(trim($qrToken))]);
    }

    public function findForUpdate(int $id): ?InventoryStockUnit
    {
        return $this->createQueryBuilder('stockUnit')
            ->andWhere('stockUnit.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /** @return list<InventoryStockUnit> */
    public function findSurplusesForParent(InventoryStockUnit $parent): array
    {
        /** @var list<InventoryStockUnit> $stockUnits */
        $stockUnits = $this->createQueryBuilder('stockUnit')
            ->innerJoin('stockUnit.variant', 'variant')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->leftJoin('stockUnit.lot', 'lot')
            ->addSelect('variant', 'unit', 'lot')
            ->andWhere('stockUnit.parentStockUnit = :parent')
            ->setParameter('parent', $parent)
            ->orderBy('stockUnit.receivedAt', 'DESC')
            ->addOrderBy('stockUnit.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $stockUnits;
    }

    public function paginateForInventory(
        ?string $search,
        ?InventoryStockUnitStatus $status,
        int $page,
        int $limit = 25,
    ): InventoryStockUnitPage {
        $page = max(1, $page);
        $limit = max(1, min($limit, 100));

        $queryBuilder = $this->createQueryBuilder('stockUnit')
            ->innerJoin('stockUnit.variant', 'variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->leftJoin('stockUnit.lot', 'lot')
            ->addSelect('variant', 'material', 'unit', 'lot');

        if ($status !== null) {
            $queryBuilder
                ->andWhere('stockUnit.status = :status')
                ->setParameter('status', $status->value);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $queryBuilder
                ->andWhere($queryBuilder->expr()->orX(
                    'LOWER(stockUnit.unitCode) LIKE :search',
                    'LOWER(variant.code) LIKE :search',
                    'LOWER(material.code) LIKE :search',
                    'LOWER(material.name) LIKE :search',
                    'LOWER(COALESCE(stockUnit.storageLocation, \'\')) LIKE :search',
                    'LOWER(COALESCE(lot.internalLotNumber, \'\')) LIKE :search',
                ))
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        $total = (int) (clone $queryBuilder)
            ->select('COUNT(stockUnit.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $limit));
        $page = min($page, $pageCount);

        /** @var list<InventoryStockUnit> $items */
        $items = $queryBuilder
            ->orderBy('stockUnit.receivedAt', 'DESC')
            ->addOrderBy('stockUnit.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new InventoryStockUnitPage($items, $total, $page, $pageCount);
    }
}
