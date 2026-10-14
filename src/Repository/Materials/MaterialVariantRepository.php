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
    /** Direct references make width and conversion part of an existing operational record. */
    private const WIDTH_EDIT_REFERENCES = [
        ['bill_of_material_items', 'material_variant_id'],
        ['service_order_operation_plans', 'material_variant_id'],
        ['production_material_usages', 'material_variant_id'],
        ['production_printing_scans', 'material_variant_id'],
        ['inventory_stock_units', 'material_variant_id'],
        ['inventory_receipt_lines', 'material_variant_id'],
        ['inventory_movements', 'material_variant_id'],
        ['inventory_stock_unit_operations', 'material_variant_id'],
        ['inventory_lots', 'material_variant_id'],
        ['inventory_cycle_counts', 'material_variant_id'],
        ['inventory_commitments', 'material_variant_id'],
        ['inventory_purchase_request_lines', 'material_variant_id'],
        ['material_variant_costs', 'material_variant_id'],
        ['material_variant_conversions', 'material_variant_id'],
        ['supplier_material_variants', 'material_variant_id'],
        ['material_substitution_rules', 'original_variant_id'],
        ['material_substitution_rules', 'substitute_variant_id'],
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaterialVariant::class);
    }

    public function hasReferencesThatLockWidth(MaterialVariant $variant): bool
    {
        $id = $variant->getId();
        if ($id === null) {
            return false;
        }

        $connection = $this->getEntityManager()->getConnection();
        foreach (self::WIDTH_EDIT_REFERENCES as [$table, $column]) {
            if ($connection->fetchOne(sprintf('SELECT 1 FROM %s WHERE %s = :id LIMIT 1', $table, $column), ['id' => $id]) !== false) {
                return true;
            }
        }

        return false;
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
