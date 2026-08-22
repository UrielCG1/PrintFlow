<?php

namespace App\Repository\Materials;

use App\Application\Materials\MaterialPage;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialCategory;
use App\Entity\Suppliers\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Material>
 */
final class MaterialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Material::class);
    }

    /**
     * @return list<Material>
     */
    public function findAvailableForMaterialForm(?Material $selected = null): array
    {
        $queryBuilder = $this->createQueryBuilder('material')
            ->innerJoin('material.category', 'category')
            ->innerJoin('material.measurementUnit', 'measurementUnit')
            ->leftJoin('material.primarySupplier', 'primarySupplier')
            ->addSelect('category', 'measurementUnit', 'primarySupplier')
            ->andWhere('material.isActive = :isActive')
            ->andWhere('category.isActive = :isActive')
            ->andWhere('measurementUnit.isActive = :isActive')
            ->andWhere(
                '(primarySupplier.id IS NULL OR primarySupplier.isActive = :isActive)',
            )
            ->setParameter('isActive', true);

        if ($selected !== null) {
            $queryBuilder
                ->orWhere('material.id = :selectedId')
                ->setParameter('selectedId', $selected->getId());
        }

        return $queryBuilder
            ->orderBy('material.isActive', 'DESC')
            ->addOrderBy('material.name', 'ASC')
            ->addOrderBy('material.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hasActiveForCategory(MaterialCategory $category): bool
    {
        return $this->createQueryBuilder('material')
            ->select('material.id')
            ->andWhere('material.category = :category')
            ->andWhere('material.isActive = :isActive')
            ->setParameter('category', $category)
            ->setParameter('isActive', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }


    /**
     * @param list<int> $measurementUnitIds
     * @return array<int, array{total: int, active: int}>
     */
    public function summarizeUsageByMeasurementUnitIds(array $measurementUnitIds): array
    {
        if ($measurementUnitIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('material')
            ->select('IDENTITY(material.measurementUnit) AS unit_id')
            ->addSelect('COUNT(material.id) AS total_count')
            ->addSelect('SUM(CASE WHEN material.isActive = true THEN 1 ELSE 0 END) AS active_count')
            ->andWhere('IDENTITY(material.measurementUnit) IN (:unitIds)')
            ->setParameter('unitIds', $measurementUnitIds)
            ->groupBy('material.measurementUnit')
            ->getQuery()
            ->getArrayResult();

        $summary = [];

        foreach ($rows as $row) {
            $summary[(int) $row['unit_id']] = [
                'total' => (int) $row['total_count'],
                'active' => (int) $row['active_count'],
            ];
        }

        return $summary;
    }

    public function hasActiveForMeasurementUnit(MeasurementUnit $measurementUnit): bool
    {
        return $this->createQueryBuilder('material')
            ->select('material.id')
            ->andWhere('material.measurementUnit = :measurementUnit')
            ->andWhere('material.isActive = :isActive')
            ->setParameter('measurementUnit', $measurementUnit)
            ->setParameter('isActive', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function hasActiveForPrimarySupplier(Supplier $supplier): bool
    {
        return $this->createQueryBuilder('material')
            ->select('material.id')
            ->andWhere('material.primarySupplier = :supplier')
            ->andWhere('material.isActive = :isActive')
            ->setParameter('supplier', $supplier)
            ->setParameter('isActive', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function paginateForAdministration(
        ?string $search,
        ?bool $isActive,
        ?int $categoryId,
        ?int $measurementUnitId,
        ?int $primarySupplierId,
        int $page,
        int $limit = 20,
    ): MaterialPage {
        $page = max(1, $page);
        $limit = max(1, min($limit, 100));

        $queryBuilder = $this->createQueryBuilder('material')
            ->innerJoin('material.category', 'category')
            ->innerJoin('material.measurementUnit', 'measurementUnit')
            ->leftJoin('material.primarySupplier', 'primarySupplier')
            ->addSelect('category', 'measurementUnit', 'primarySupplier');

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('material.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        if ($categoryId !== null && $categoryId > 0) {
            $queryBuilder
                ->andWhere('category.id = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        if ($measurementUnitId !== null && $measurementUnitId > 0) {
            $queryBuilder
                ->andWhere('measurementUnit.id = :measurementUnitId')
                ->setParameter('measurementUnitId', $measurementUnitId);
        }

        if ($primarySupplierId !== null && $primarySupplierId > 0) {
            $queryBuilder
                ->andWhere('primarySupplier.id = :primarySupplierId')
                ->setParameter('primarySupplierId', $primarySupplierId);
        }

        $search = trim((string) $search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere(
                    $queryBuilder->expr()->orX(
                        'LOWER(material.code) LIKE :search',
                        'LOWER(material.name) LIKE :search',
                        'LOWER(COALESCE(material.description, \'\')) LIKE :search',
                        'LOWER(category.code) LIKE :search',
                        'LOWER(category.name) LIKE :search',
                        'LOWER(measurementUnit.code) LIKE :search',
                        'LOWER(measurementUnit.name) LIKE :search',
                        'LOWER(COALESCE(primarySupplier.code, \'\')) LIKE :search',
                        'LOWER(COALESCE(primarySupplier.businessName, \'\')) LIKE :search',
                    ),
                )
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        $totalQueryBuilder = clone $queryBuilder;

        $total = (int) $totalQueryBuilder
            ->select('COUNT(material.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $limit));
        $page = min($page, $pageCount);

        $items = $queryBuilder
            ->orderBy('material.isActive', 'DESC')
            ->addOrderBy('material.name', 'ASC')
            ->addOrderBy('material.code', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new MaterialPage(
            items: $items,
            total: $total,
            currentPage: $page,
            pageCount: $pageCount,
        );
    }
}