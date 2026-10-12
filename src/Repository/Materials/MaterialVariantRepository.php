<?php

declare(strict_types=1);

namespace App\Repository\Materials;

use App\Entity\Materials\MaterialVariant;
use App\Entity\Inventory\MaterialVariantCost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MaterialVariant> */
final class MaterialVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaterialVariant::class);
    }

    public function findActiveByBarcode(string $barcode): ?MaterialVariant
    {
        /** @var MaterialVariant|null $variant */
        $variant = $this->createQueryBuilder('variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.consumptionUnit', 'unit')
            ->addSelect('material', 'unit')
            ->andWhere('variant.barcode = :barcode')
            ->andWhere('variant.isActive = true')
            ->andWhere('material.isActive = true')
            ->andWhere('material.isConsumable = true')
            ->andWhere('unit.isActive = true')
            ->setParameter('barcode', strtoupper(trim($barcode)))
            ->getQuery()
            ->getOneOrNullResult();

        return $variant;
    }

    /** @return list<MaterialVariant> */
    public function findActiveConsumable(): array
    {
        /** @var list<MaterialVariant> $variants */
        $variants = $this->createQueryBuilder('variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.consumptionUnit', 'unit')
            ->addSelect('material', 'unit')
            ->andWhere('variant.isActive = true')
            ->andWhere('material.isActive = true')
            ->andWhere('material.isConsumable = true')
            ->andWhere('unit.isActive = true')
            ->orderBy('material.name', 'ASC')
            ->addOrderBy('variant.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $variants;
    }

    /** @return list<MaterialVariant> */
    public function findActiveStockItems(): array
    {
        /** @var list<MaterialVariant> $variants */
        $variants = $this->createQueryBuilder('variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->addSelect('material', 'unit')
            ->andWhere('variant.isActive = true')
            ->andWhere('material.isActive = true')
            ->andWhere('material.isStockItem = true')
            ->orderBy('material.name', 'ASC')
            ->addOrderBy('variant.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $variants;
    }

    /** @return list<array{variant: MaterialVariant, availableQuantity: string}> */
    public function findLowStockAlerts(int $limit = 8): array
    {
        $rows = $this->createQueryBuilder('variant')
            ->leftJoin(MaterialVariantCost::class, 'balance', 'WITH', 'balance.variant = variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->addSelect('material', 'unit')
            ->addSelect('(COALESCE(balance.onHandQuantity, 0) - COALESCE(balance.blockedQuantity, 0) - COALESCE(balance.committedQuantity, 0)) AS available_quantity')
            ->andWhere('variant.isActive = true')
            ->andWhere('material.isActive = true')
            ->andWhere('material.isStockItem = true')
            ->andWhere('variant.minimumStock > 0')
            ->andWhere('(COALESCE(balance.onHandQuantity, 0) - COALESCE(balance.blockedQuantity, 0) - COALESCE(balance.committedQuantity, 0)) <= variant.minimumStock')
            ->orderBy('available_quantity', 'ASC')
            ->addOrderBy('material.name', 'ASC')
            ->setMaxResults(max(1, min($limit, 50)))
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => [
            'variant' => $row[0],
            'availableQuantity' => (string) $row['available_quantity'],
        ], $rows);
    }
}
