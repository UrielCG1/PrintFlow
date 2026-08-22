<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\MeasurementDimensionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MeasurementUnit> */
final class MeasurementUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MeasurementUnit::class);
    }

    /**
     * @return array{
     *     items: list<MeasurementUnit>,
     *     currentPage: int,
     *     totalPages: int,
     *     totalItems: int
     * }
     */
    public function paginateForAdministration(
        string $search = '',
        ?bool $isActive = true,
        ?MeasurementDimensionType $dimension = null,
        int $page = 1,
        int $perPage = 100,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $queryBuilder = $this->createQueryBuilder('unit')
            ->leftJoin('unit.baseUnit', 'baseUnit')
            ->addSelect('baseUnit');

        $search = trim($search);
        if ($search !== '') {
            $queryBuilder
                ->andWhere('unit.code LIKE :search OR unit.name LIKE :search OR unit.symbol LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('unit.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        if ($dimension !== null) {
            $queryBuilder
                ->andWhere('unit.dimensionType = :dimension')
                ->setParameter('dimension', $dimension->value);
        }

        $totalItems = (int) (clone $queryBuilder)
            ->select('COUNT(unit.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        /** @var list<MeasurementUnit> $items */
        $items = $queryBuilder
            ->orderBy('unit.displayOrder', 'ASC')
            ->addOrderBy('unit.name', 'ASC')
            ->addOrderBy('unit.id', 'ASC')
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

    /** @return list<MeasurementUnit> */
    public function findActiveOrdered(): array
    {
        /** @var list<MeasurementUnit> $units */
        $units = $this->createQueryBuilder('unit')
            ->leftJoin('unit.baseUnit', 'baseUnit')
            ->addSelect('baseUnit')
            ->andWhere('unit.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('unit.displayOrder', 'ASC')
            ->addOrderBy('unit.name', 'ASC')
            ->addOrderBy('unit.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $units;
    }

    /** @return list<MeasurementUnit> */
    public function findActiveDimensionOrderedForUpdate(string $dimensionType): array
    {
        /** @var list<MeasurementUnit> $units */
        $units = $this->createQueryBuilder('unit')
            ->andWhere('unit.isActive = :isActive')
            ->andWhere('unit.dimensionType = :dimensionType')
            ->setParameter('isActive', true)
            ->setParameter('dimensionType', $dimensionType)
            ->orderBy('unit.displayOrder', 'ASC')
            ->addOrderBy('unit.name', 'ASC')
            ->addOrderBy('unit.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $units;
    }

    /** @return list<MeasurementUnit> */
    public function findAvailableForItemForm(?MeasurementUnit $selected = null): array
    {
        $queryBuilder = $this->createQueryBuilder('unit')
            ->andWhere('unit.isActive = :isActive')
            ->setParameter('isActive', true);

        if ($selected !== null) {
            $queryBuilder
                ->orWhere('unit.id = :selectedId')
                ->setParameter('selectedId', $selected->getId());
        }

        /** @var list<MeasurementUnit> $units */
        $units = $queryBuilder
            ->orderBy('unit.isActive', 'DESC')
            ->addOrderBy('unit.dimensionType', 'ASC')
            ->addOrderBy('unit.displayOrder', 'ASC')
            ->addOrderBy('unit.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $units;
    }

    /** @return list<MeasurementUnit> */
    public function findAvailableForMaterialForm(?MeasurementUnit $selected = null): array
    {
        return $this->findAvailableForItemForm($selected);
    }

    /**
     * Unidades candidatas a base: activas, principales (sin base propia) y sin
     * incluir la unidad que se está editando. Se conserva la base seleccionada
     * aunque esté inactiva para no volver imposible editar registros legados.
     *
     * @return list<MeasurementUnit>
     */
    public function findAvailableBaseUnits(
        ?MeasurementUnit $editingUnit = null,
        ?MeasurementUnit $selectedBaseUnit = null,
    ): array {
        $queryBuilder = $this->createQueryBuilder('unit');

        if ($selectedBaseUnit !== null) {
            $queryBuilder
                ->andWhere('(unit.baseUnit IS NULL OR unit.id = :selectedBaseId)')
                ->andWhere('(unit.isActive = :isActive OR unit.id = :selectedBaseId)')
                ->setParameter('isActive', true)
                ->setParameter('selectedBaseId', $selectedBaseUnit->getId());
        } else {
            $queryBuilder
                ->andWhere('unit.baseUnit IS NULL')
                ->andWhere('unit.isActive = :isActive')
                ->setParameter('isActive', true);
        }


        if ($editingUnit !== null) {
            $queryBuilder
                ->andWhere('unit.id != :editingId')
                ->setParameter('editingId', $editingUnit->getId());
        }

        /** @var list<MeasurementUnit> $units */
        $units = $queryBuilder
            ->orderBy('unit.dimensionType', 'ASC')
            ->addOrderBy('unit.displayOrder', 'ASC')
            ->addOrderBy('unit.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $units;
    }


    /**
     * @param list<int> $baseUnitIds
     * @return array<int, int>
     */
    public function countActiveDerivedByBaseUnitIds(array $baseUnitIds): array
    {
        if ($baseUnitIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('unit')
            ->select('IDENTITY(unit.baseUnit) AS base_unit_id')
            ->addSelect('COUNT(unit.id) AS derived_count')
            ->andWhere('IDENTITY(unit.baseUnit) IN (:baseUnitIds)')
            ->andWhere('unit.isActive = :isActive')
            ->setParameter('baseUnitIds', $baseUnitIds)
            ->setParameter('isActive', true)
            ->groupBy('unit.baseUnit')
            ->getQuery()
            ->getArrayResult();

        $summary = [];

        foreach ($rows as $row) {
            $summary[(int) $row['base_unit_id']] = (int) $row['derived_count'];
        }

        return $summary;
    }

    public function hasDerivedUnits(MeasurementUnit $baseUnit): bool
    {
        return $this->createQueryBuilder('unit')
            ->select('1')
            ->andWhere('unit.baseUnit = :baseUnit')
            ->setParameter('baseUnit', $baseUnit)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function hasActiveDerivedUnits(MeasurementUnit $baseUnit): bool
    {
        return $this->createQueryBuilder('unit')
            ->select('1')
            ->andWhere('unit.baseUnit = :baseUnit')
            ->andWhere('unit.isActive = :isActive')
            ->setParameter('baseUnit', $baseUnit)
            ->setParameter('isActive', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function nextDisplayOrderForDimension(string $dimensionType): int
    {
        $max = $this->createQueryBuilder('unit')
            ->select('MAX(unit.displayOrder)')
            ->andWhere('unit.dimensionType = :dimensionType')
            ->setParameter('dimensionType', $dimensionType)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 10;
    }

    /** @return list<MeasurementUnit> */
    public function findAllForHealthAssessment(): array
    {
        /** @var list<MeasurementUnit> $units */
        $units = $this->createQueryBuilder('unit')
            ->leftJoin('unit.baseUnit', 'baseUnit')
            ->leftJoin('baseUnit.baseUnit', 'baseBaseUnit')
            ->addSelect('baseUnit', 'baseBaseUnit')
            ->orderBy('unit.isActive', 'DESC')
            ->addOrderBy('unit.dimensionType', 'ASC')
            ->addOrderBy('unit.displayOrder', 'ASC')
            ->addOrderBy('unit.name', 'ASC')
            ->addOrderBy('unit.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $units;
    }


}
