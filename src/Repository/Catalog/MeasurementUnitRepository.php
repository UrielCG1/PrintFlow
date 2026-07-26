<?php

namespace App\Repository\Catalog;

use App\Entity\Catalog\MeasurementUnit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\LockMode;

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
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $queryBuilder = $this->createQueryBuilder('unit');

        $search = trim($search);
        if ($search !== '') {
            $queryBuilder
                ->andWhere('unit.code LIKE :search OR unit.name LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('unit.isActive = :isActive')
                ->setParameter('isActive', $isActive);
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
     * @return list<MeasurementUnit>
     */
    public function findActiveOrderedForUpdate(): array
    {
        /** @var list<MeasurementUnit> $units */
        $units = $this->createQueryBuilder('unit')
            ->andWhere('unit.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('unit.displayOrder', 'ASC')
            ->addOrderBy('unit.name', 'ASC')
            ->addOrderBy('unit.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $units;
    }
}