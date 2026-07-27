<?php

declare(strict_types=1);

namespace App\Repository\Operations;

use App\Entity\Operations\OperationArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OperationArea> */
final class OperationAreaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OperationArea::class);
    }

    /**
     * @return array{items: list<OperationArea>, currentPage: int, totalPages: int, totalItems: int}
     */
    public function paginateForAdministration(
        string $search = '',
        ?bool $isActive = true,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $queryBuilder = $this->createQueryBuilder('area');

        $search = trim($search);
        if ($search !== '') {
            $queryBuilder
                ->andWhere('area.code LIKE :search OR area.name LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('area.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        $totalItems = (int) (clone $queryBuilder)
            ->select('COUNT(area.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        /** @var list<OperationArea> $items */
        $items = $queryBuilder
            ->orderBy('area.displayOrder', 'ASC')
            ->addOrderBy('area.name', 'ASC')
            ->addOrderBy('area.id', 'ASC')
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

    /** @return list<OperationArea> */
    public function findActiveOrderedForUpdate(): array
    {
        /** @var list<OperationArea> $areas */
        $areas = $this->createQueryBuilder('area')
            ->andWhere('area.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('area.displayOrder', 'ASC')
            ->addOrderBy('area.name', 'ASC')
            ->addOrderBy('area.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $areas;
    }

    /** @return list<OperationArea> */
    public function findAllOrdered(): array
    {
        /** @var list<OperationArea> $areas */
        $areas = $this->createQueryBuilder('area')
            ->orderBy('area.isActive', 'DESC')
            ->addOrderBy('area.displayOrder', 'ASC')
            ->addOrderBy('area.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $areas;
    }

    /** @return list<OperationArea> */
    public function findAvailableForOperationForm(?OperationArea $selected = null): array
    {
        $queryBuilder = $this->createQueryBuilder('area')
            ->andWhere('area.isActive = :isActive')
            ->setParameter('isActive', true);

        if ($selected !== null) {
            $queryBuilder
                ->orWhere('area.id = :selectedId')
                ->setParameter('selectedId', $selected->getId());
        }

        /** @var list<OperationArea> $areas */
        $areas = $queryBuilder
            ->orderBy('area.isActive', 'DESC')
            ->addOrderBy('area.displayOrder', 'ASC')
            ->addOrderBy('area.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $areas;
    }
}