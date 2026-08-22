<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\CommercialItemType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommercialItem> */
final class CommercialItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommercialItem::class);
    }

    /**
     * Recupera el concepto real habilitado para una nueva cotización. Así el
     * manager no usa como fuente de verdad la entidad enviada por el
     * formulario.
     */
    public function findActiveForQuotation(int $id): ?CommercialItem
    {
        return $this->createQueryBuilder('item')
            ->andWhere('item.id = :id')
            ->andWhere('item.isActive = :isActive')
            ->setParameter('id', $id)
            ->setParameter('isActive', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{
     *     items: list<CommercialItem>,
     *     currentPage: int,
     *     totalPages: int,
     *     totalItems: int
     * }
     */
    public function paginateForAdministration(
        string $search = '',
        ?bool $isActive = true,
        ?CommercialItemType $type = null,
        ?int $categoryId = null,
        ?int $measurementUnitId = null,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $queryBuilder = $this->createQueryBuilder('item')
            ->innerJoin('item.category', 'category')
            ->innerJoin('item.measurementUnit', 'measurementUnit')
            ->addSelect('category', 'measurementUnit');

        $search = trim($search);
        if ($search !== '') {
            $queryBuilder
                ->andWhere('
                    item.code LIKE :search
                    OR item.name LIKE :search
                    OR category.code LIKE :search
                    OR category.name LIKE :search
                    OR measurementUnit.code LIKE :search
                    OR measurementUnit.name LIKE :search
                ')
                ->setParameter('search', '%'.$search.'%');
        }

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('item.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        if ($type !== null) {
            $queryBuilder
                ->andWhere('item.type = :type')
                ->setParameter('type', $type->value);
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

        $totalItems = (int) (clone $queryBuilder)
            ->select('COUNT(item.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        /** @var list<CommercialItem> $items */
        $items = $queryBuilder
            ->orderBy('item.name', 'ASC')
            ->addOrderBy('item.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
        ];
    }


    /**
     * @param list<int> $categoryIds
     * @return array<int, array{total: int, active: int}>
     */
    public function summarizeUsageByCategoryIds(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('item')
            ->select('IDENTITY(item.category) AS category_id')
            ->addSelect('COUNT(item.id) AS total_count')
            ->addSelect('SUM(CASE WHEN item.isActive = true THEN 1 ELSE 0 END) AS active_count')
            ->andWhere('IDENTITY(item.category) IN (:categoryIds)')
            ->setParameter('categoryIds', $categoryIds)
            ->groupBy('item.category')
            ->getQuery()
            ->getArrayResult();

        $summary = [];

        foreach ($rows as $row) {
            $summary[(int) $row['category_id']] = [
                'total' => (int) $row['total_count'],
                'active' => (int) $row['active_count'],
            ];
        }

        return $summary;
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

        $rows = $this->createQueryBuilder('item')
            ->select('IDENTITY(item.measurementUnit) AS unit_id')
            ->addSelect('COUNT(item.id) AS total_count')
            ->addSelect('SUM(CASE WHEN item.isActive = true THEN 1 ELSE 0 END) AS active_count')
            ->andWhere('IDENTITY(item.measurementUnit) IN (:unitIds)')
            ->setParameter('unitIds', $measurementUnitIds)
            ->groupBy('item.measurementUnit')
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

    public function hasActiveForCategory(CommercialCategory $category): bool
    {
        return $this->createQueryBuilder('item')
            ->select('1')
            ->andWhere('item.category = :category')
            ->andWhere('item.isActive = :isActive')
            ->setParameter('category', $category)
            ->setParameter('isActive', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function hasActiveForMeasurementUnit(MeasurementUnit $measurementUnit): bool
    {
        return $this->createQueryBuilder('item')
            ->select('1')
            ->andWhere('item.measurementUnit = :measurementUnit')
            ->andWhere('item.isActive = :isActive')
            ->setParameter('measurementUnit', $measurementUnit)
            ->setParameter('isActive', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /** @return list<CommercialItem> */
    public function findAllForHealthAssessment(): array
    {
        /** @var list<CommercialItem> $items */
        $items = $this->createQueryBuilder('item')
            ->innerJoin('item.category', 'category')
            ->innerJoin('item.measurementUnit', 'measurementUnit')
            ->addSelect('category', 'measurementUnit')
            ->orderBy('item.isActive', 'DESC')
            ->addOrderBy('item.name', 'ASC')
            ->addOrderBy('item.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }


}